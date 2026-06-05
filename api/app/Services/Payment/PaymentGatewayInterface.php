<?php

namespace App\Services\Payment;

use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Cria uma cobrança Pix e retorna os dados para exibição.
     *
     * @param  Payment  $payment
     * @param  array    $context  ['table_number', 'restaurant_name', 'client_name']
     * @return array   ['gateway_payment_id', 'gateway_status', 'qr_code',
     *                  'qr_code_base64', 'ticket_url', 'expires_at']
     */
    public function createPixCharge(Payment $payment, array $context): array;

    /**
     * Consulta o status atual de uma cobrança no gateway.
     * Retorna o status normalizado: 'pending' | 'paid' | 'failed' | 'expired'
     */
    public function getPaymentStatus(string $gatewayPaymentId): string;
}
