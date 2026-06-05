<?php

namespace App\Services\Terminal;

use App\Models\Payment;

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

    public function getAcquirerName(): string
    {
        return 'mercadopago';
    }
}
