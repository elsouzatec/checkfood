<?php

namespace App\Http\Controllers;

use App\Models\AppSession;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use App\Services\Terminal\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private TerminalService $terminalService,
    ) {}

    /**
     * GET /api/payments/{payment}
     * Polling de status — cliente ou garçom.
     */
    public function show(Payment $payment, Request $request): JsonResponse
    {
        $this->authorizePayment($payment, $request);

        return response()->json([
            'payment' => $this->formatPayment($payment),
        ]);
    }

    /**
     * POST /api/payments/{payment}/pay
     * Seleciona método: pix | dinheiro | cartao
     */
    public function pay(Payment $payment, Request $request): JsonResponse
    {
        $this->authorizePayment($payment, $request);

        $request->validate([
            'method' => 'required|string|in:pix,dinheiro,cartao',
        ]);

        // Dinheiro/cartão marcam como pago na hora: só a equipe atesta que recebeu.
        // O cliente (app da mesa) só pode gerar PIX, que é confirmado pelo gateway.
        if ($request->method !== 'pix' && ! $this->isStaff($request)) {
            return response()->json(['message' => 'Pagamento em dinheiro ou cartão é registrado pela equipe.'], 403);
        }

        if ($payment->isPaid()) {
            return response()->json([
                'message' => 'Pagamento já confirmado.',
                'payment' => $this->formatPayment($payment),
            ]);
        }

        $session = $payment->session()->with(['table', 'restaurant', 'user'])->first();
        $method = $request->method;

        try {
            if ($method === 'pix') {
                $payment = $this->paymentService->payWithPix($payment, $session);

                return response()->json([
                    'message' => 'QR Code Pix gerado.',
                    'payment' => $this->formatPayment($payment),
                ]);
            }

            $payment = $this->paymentService->payManual($payment, $method);

            return response()->json([
                'message' => 'Pagamento registrado.',
                'payment' => $this->formatPayment($payment),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao processar pagamento.'], 500);
        }
    }

    /**
     * POST /api/payments/{payment}/deep-link
     * Gera o deep link para acionar o terminal POS.
     * Body: { back_url: string }
     */
    public function deepLink(Payment $payment, Request $request): JsonResponse
    {
        $this->authorizePayment($payment, $request);

        $request->validate([
            'back_url' => 'required|string|url',
        ]);

        if ($payment->isPaid()) {
            return response()->json(['message' => 'Pagamento já confirmado.'], 422);
        }

        $session = $payment->session()->with(['table', 'restaurant'])->first();
        $restaurant = $session->restaurant;

        if (! $restaurant->terminal_integration_enabled) {
            return response()->json(['message' => 'Integração com terminal não está ativa.'], 422);
        }

        try {
            $deepLink = $this->terminalService->generateDeepLink(
                $payment,
                $restaurant,
                $request->back_url
            );

            // Marca o payment como "terminal iniciado"
            $payment->update([
                'method' => 'cartao',
                'gateway' => $restaurant->terminal_acquirer,
            ]);

            return response()->json([
                'deep_link' => $deepLink,
                'payment' => $this->formatPayment($payment->fresh()),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('deepLink: falha ao gerar deep link do terminal', [
                'payment_id' => $payment->id,
                'restaurant_id' => $restaurant->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao acionar o terminal.'], 500);
        }
    }

    /**
     * POST /api/payments/{payment}/confirm-pos
     * Callback do terminal após pagamento.
     * Body: { status, terminal_payment_id, external_reference }
     */
    public function confirmPos(Payment $payment, Request $request): JsonResponse
    {
        $this->authorizePayment($payment, $request);

        // Terminal é operado pela equipe; cliente da mesa nunca confirma pagamento.
        if (! $this->isStaff($request)) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:approved,rejected,pending',
            'terminal_payment_id' => 'required_if:status,approved|nullable|string|max:100',
            'external_reference' => 'required|string',
        ]);

        // Valida que o external_reference bate com este payment
        if ($request->external_reference !== (string) $payment->id) {
            return response()->json(['message' => 'Referência inválida.'], 422);
        }

        if ($payment->isPaid()) {
            return response()->json([
                'message' => 'Pagamento já confirmado.',
                'payment' => $this->formatPayment($payment),
            ]);
        }

        $restaurant = $payment->restaurant;

        // Só aceita callback de pagamento que foi de fato enviado ao terminal (deep-link).
        if (! $restaurant->terminal_integration_enabled || $payment->gateway !== $restaurant->terminal_acquirer) {
            return response()->json(['message' => 'Este pagamento não foi enviado ao terminal.'], 422);
        }

        if ($request->status === 'approved') {
            $terminalPaymentId = (string) $request->terminal_payment_id;

            // Uma transação da adquirente não pode quitar dois pagamentos.
            $alreadyUsed = Payment::where('gateway_payment_id', $terminalPaymentId)
                ->where('id', '!=', $payment->id)
                ->exists();

            if ($alreadyUsed || ! $this->terminalService->verifyApproved($payment, $restaurant, $terminalPaymentId)) {
                \Log::warning('confirmPos: aprovação não confirmada pela adquirente', [
                    'payment_id' => $payment->id,
                    'restaurant_id' => $payment->restaurant_id,
                    'terminal_payment_id' => $terminalPaymentId,
                    'already_used' => $alreadyUsed,
                ]);

                return response()->json([
                    'message' => 'Pagamento não confirmado pela adquirente. Confira no terminal antes de liberar a mesa.',
                    'payment' => $this->formatPayment($payment),
                ], 422);
            }

            try {
                $payment->update([
                    'gateway_payment_id' => $request->terminal_payment_id,
                    'gateway_status' => 'approved',
                ]);

                $this->paymentService->confirmFromGateway($payment);
            } catch (\Throwable $e) {
                \Log::error('confirmPos: falha ao confirmar pagamento do terminal', [
                    'payment_id' => $payment->id,
                    'restaurant_id' => $payment->restaurant_id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Erro ao confirmar pagamento.'], 500);
            }

            return response()->json([
                'message' => 'Pagamento confirmado.',
                'payment' => $this->formatPayment($payment->fresh()),
            ]);
        }

        if ($request->status === 'rejected') {
            $payment->update([
                'gateway_status' => 'rejected',
                // Não fecha a sessão — garçom pode tentar outro método
            ]);

            return response()->json([
                'message' => 'Pagamento recusado pelo terminal.',
                'payment' => $this->formatPayment($payment->fresh()),
            ], 422);
        }

        return response()->json([
            'message' => 'Pagamento pendente.',
            'payment' => $this->formatPayment($payment),
        ]);
    }

    // ── helpers ──────────────────────────────────────────

    private function isStaff(Request $request): bool
    {
        return in_array($request->user()->role->value, ['waiter', 'manager', 'cashier'], true);
    }

    private function authorizePayment(Payment $payment, Request $request): void
    {
        $user = $request->user();

        if (in_array($user->role->value, ['waiter', 'manager', 'kitchen', 'cashier'])) {
            if ($payment->restaurant_id !== $user->restaurant_id) {
                abort(403, 'Acesso negado.');
            }

            return;
        }

        $session = AppSession::find($payment->session_id);
        if (! $session || $session->user_id !== $user->id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function formatPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'status' => $payment->status,
            'gateway' => $payment->gateway,
            'gateway_status' => $payment->gateway_status,
            'qr_code' => $payment->qr_code,
            'qr_code_base64' => $payment->qr_code_base64,
            'ticket_url' => $payment->ticket_url,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
