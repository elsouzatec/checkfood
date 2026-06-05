<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentGatewayFactory $gatewayFactory,
    ) {}

    /**
     * POST /api/webhooks/mercadopago
     * Rota pública — sem middleware auth.
     *
     * Fluxo multi-tenant:
     *  1. Localiza o Payment pelo gateway_payment_id
     *  2. Identifica o tenant (Payment → Restaurant)
     *  3. Valida assinatura com o webhook_secret DO TENANT
     *     (com fallback no env para compatibilidade)
     *  4. Consulta status no MP usando o access_token DO TENANT
     */
    public function mercadopago(Request $request): JsonResponse
    {
        $type = $request->input('type') ?? $request->input('topic');

        // Só processa notificações de pagamento
        if ($type !== 'payment') {
            return response()->json(['ok' => true]);
        }

        $gatewayPaymentId = (string) ($request->input('data.id') ?? $request->input('id'));

        if (! $gatewayPaymentId) {
            return response()->json(['message' => 'ID do pagamento não informado.'], 422);
        }

        $payment = Payment::with('restaurant')
            ->where('gateway_payment_id', $gatewayPaymentId)
            ->where('gateway', 'mercadopago')
            ->first();

        if (! $payment) {
            // Pode ser notificação de outro sistema — retorna 200 para MP não retentar
            return response()->json(['ok' => true]);
        }

        $tenantSecret = $payment->restaurant?->mercadopago_webhook_secret
            ?? config('services.mercadopago.webhook_secret');

        if (! $this->validateSignature($request, $tenantSecret)) {
            Log::warning('MercadoPago webhook: assinatura inválida', [
                'payment_id' => $payment->id,
                'restaurant_id' => $payment->restaurant_id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        if ($payment->isPaid()) {
            return response()->json(['ok' => true]);
        }

        $tenantToken = $payment->restaurant?->mercadopago_access_token;

        try {
            // Verifica status normalizado via gateway com credenciais do tenant
            $status = $this->gatewayFactory->withToken($tenantToken)
                ->getPaymentStatus($gatewayPaymentId);

            if ($status === 'paid') {
                $this->paymentService->confirmFromGateway($payment);
            } elseif ($status === 'failed') {
                $payment->update(['status' => 'failed', 'gateway_status' => 'rejected']);
            }
        } catch (\Throwable $e) {
            // Falha ao consultar/confirmar no gateway (MP fora do ar, token inválido, etc.).
            // Loga com contexto e devolve 502 para o MP re-tentar a notificação.
            Log::error('MercadoPago webhook: falha ao processar pagamento', [
                'payment_id' => $payment->id,
                'restaurant_id' => $payment->restaurant_id,
                'gateway_id' => $gatewayPaymentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao processar notificação.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Valida o header X-Signature do Mercado Pago.
     * Formato: ts=TIMESTAMP,v1=HMAC_HEX
     * Manifest: "id:{data.id};request-id:{x-request-id};ts:{ts};"
     */
    private function validateSignature(Request $request, ?string $secret): bool
    {
        // Em ambiente de desenvolvimento/sandbox sem secret configurado, aceita tudo
        if (empty($secret)) {
            return true;
        }

        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId = $request->input('data.id', '');

        if (! $xSignature) {
            return false;
        }

        // Extrair ts e v1 do header
        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if (! $ts || ! $v1) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $computed = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computed, $v1);
    }
}
