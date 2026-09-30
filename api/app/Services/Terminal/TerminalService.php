<?php

namespace App\Services\Terminal;

use App\Models\Payment;
use App\Models\Restaurant;

class TerminalService
{
    /**
     * Retorna o gateway correto para a adquirente informada.
     * Para adicionar uma nova adquirente: registre aqui e crie a classe.
     */
    public function gateway(string $acquirer): TerminalGatewayInterface
    {
        return match ($acquirer) {
            'mercadopago' => new MercadoPagoTerminalGateway(),
            // 'pagseguro'   => new PagSeguroTerminalGateway(),   // futuro
            // 'stone'       => new StoneTerminalGateway(),       // futuro
            default => throw new \InvalidArgumentException(
                "Adquirente '{$acquirer}' não está configurada."
            ),
        };
    }

    /**
     * Gera o deep link para o terminal usando a adquirente do restaurante.
     */
    public function generateDeepLink(
        Payment    $payment,
        Restaurant $restaurant,
        string     $backUrl
    ): string {
        $gateway = $this->gateway($restaurant->terminal_acquirer ?? 'mercadopago');

        $table = $payment->session->table;

        return $gateway->generateDeepLink($payment, [
            'table_number'    => $table->number,
            'restaurant_name' => $restaurant->name,
            'back_url'        => $backUrl,
        ]);
    }

    /**
     * Confirma a transação na API da adquirente do restaurante.
     */
    public function verifyApproved(Payment $payment, Restaurant $restaurant, string $terminalPaymentId): bool
    {
        return $this->gateway($restaurant->terminal_acquirer ?? 'mercadopago')
            ->verifyApproved($payment, $restaurant, $terminalPaymentId);
    }

    /**
     * Interpreta o callback recebido na back_url.
     */
    public function parseCallback(string $acquirer, array $params): array
    {
        return $this->gateway($acquirer)->parseCallback($params);
    }
}
