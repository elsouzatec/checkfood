<?php

namespace App\Services\Payment;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';

    /**
     * @param  string|null  $accessToken  Token específico do tenant.
     *         Se null, cai no env (modo legacy — conta única da plataforma).
     */
    public function __construct(?string $accessToken = null)
    {
        $token = $accessToken ?? config('services.mercadopago.access_token');

        if (!$token) {
            throw new \RuntimeException(
                'Mercado Pago access token não configurado para este restaurante.'
            );
        }

        $this->accessToken = $token;
    }

    public function createPixCharge(Payment $payment, array $context): array
    {
        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'X-Idempotency-Key' => 'pagfood-payment-' . $payment->id,
            ])
            ->post("{$this->baseUrl}/v1/payments", [
                'transaction_amount' => round((float) $payment->amount, 2),
                'description'        => "Mesa {$context['table_number']} — {$context['restaurant_name']}",
                'payment_method_id'  => 'pix',
                'payer'              => [
                    'email'      => 'pagamento@pagfood.online',
                    'first_name' => $context['client_name'] ?? 'Cliente',
                ],
                'notification_url'   => url('/api/webhooks/mercadopago'),
                'date_of_expiration' => now()->addMinutes(30)->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z'),
            ]);

        if (!$response->successful()) {
            Log::error('MercadoPago createPixCharge failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->json(),
            ]);
            throw new \RuntimeException('Erro ao gerar cobrança Pix no Mercado Pago.');
        }

        $data = $response->json();
        $txData = $data['point_of_interaction']['transaction_data'] ?? [];

        return [
            'gateway_payment_id' => (string) $data['id'],
            'gateway_status'     => $data['status'],
            'qr_code'            => $txData['qr_code']        ?? null,
            'qr_code_base64'     => $txData['qr_code_base64'] ?? null,
            'ticket_url'         => $txData['ticket_url']     ?? null,
            'expires_at'         => now()->addMinutes(30),
        ];
    }

    public function getPaymentStatus(string $gatewayPaymentId): string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/v1/payments/{$gatewayPaymentId}");

        if (!$response->successful()) {
            return 'pending';
        }

        $mpStatus = $response->json('status');

        return match ($mpStatus) {
            'approved'             => 'paid',
            'rejected', 'cancelled'=> 'failed',
            'in_process', 'pending'=> 'pending',
            default                => 'pending',
        };
    }
}
