<?php

namespace App\Services\Terminal;

use App\Models\Payment;
use App\Models\Restaurant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com Mercado Pago Point Smart (Sunmi P2 com app MP).
 *
 * Protocolo: mercadopago:// (deep link Android)
 * App receptor: com.mercadopago.wallet
 *
 * Após o pagamento, o app MP redireciona para back_url com:
 *   ?status=approved|rejected|pending
 *   &payment_id={mp_payment_id}
 *   &external_reference={nosso_payment_id}
 */
class MercadoPagoTerminalGateway implements TerminalGatewayInterface
{
    public function generateDeepLink(Payment $payment, array $context): string
    {
        $params = http_build_query([
            'amount'             => number_format((float) $payment->amount, 2, '.', ''),
            'description'        => "Mesa {$context['table_number']} — {$context['restaurant_name']}",
            'external_reference' => (string) $payment->id,
            'back_url'           => $context['back_url'],
            'notification_url'   => url('/api/webhooks/mercadopago'),
        ]);

        return "mercadopago://payment?{$params}";
    }

    public function parseCallback(array $params): array
    {
        return [
            'status'             => $params['status']             ?? 'pending',
            'terminal_payment_id'=> $params['payment_id']         ?? null,
            'external_reference' => $params['external_reference'] ?? null,
        ];
    }

    public function verifyApproved(Payment $payment, Restaurant $restaurant, string $terminalPaymentId): bool
    {
        $token = $restaurant->mercadopago_access_token ?? config('services.mercadopago.access_token');

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://api.mercadopago.com/v1/payments/'.rawurlencode($terminalPaymentId));
        } catch (ConnectionException $e) {
            // Timeout/rede: sem prova de aprovação, não quita (o garçom confere no terminal).
            Log::warning('verifyApproved: sem conexão com o Mercado Pago', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('verifyApproved: consulta ao Mercado Pago falhou', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
            ]);

            return false;
        }

        return $response->json('status') === 'approved'
            && (string) $response->json('external_reference') === (string) $payment->id
            && abs((float) $response->json('transaction_amount') - (float) $payment->amount) < 0.01;
    }

    public function getAcquirerName(): string
    {
        return 'mercadopago';
    }
}
