<?php

namespace App\Services\Terminal;

use App\Models\Payment;
use App\Models\Restaurant;

/**
 * Contrato para integração com terminais POS (maquininhas).
 *
 * Para adicionar uma nova adquirente no futuro:
 *  1. Crie uma classe que implemente esta interface
 *  2. Registre-a no TerminalService::gateway()
 *  3. Adicione a opção no admin (terminal_acquirer)
 *
 * Nenhum outro arquivo precisa ser alterado.
 */
interface TerminalGatewayInterface
{
    /**
     * Gera a URL de deep link para acionar o app da adquirente no terminal.
     *
     * @param  Payment $payment  Payment record com amount e id
     * @param  array   $context  ['table_number', 'restaurant_name', 'back_url']
     * @return string  URL de deep link pronta para window.location.href
     */
    public function generateDeepLink(Payment $payment, array $context): string;

    /**
     * Interpreta os query params do callback enviado pela adquirente
     * após o pagamento e retorna um array normalizado.
     *
     * @param  array $params  Query params recebidos na back_url
     * @return array ['status' => 'approved|rejected|pending',
     *               'terminal_payment_id' => string,
     *               'external_reference'  => string]
     */
    public function parseCallback(array $params): array;

    /**
     * Confirma na API da adquirente que a transação foi aprovada e pertence a
     * este pagamento (referência + valor). O callback chega pelo navegador do
     * garçom e não prova nada sozinho — sem esta checagem, qualquer um forja
     * "approved". Adquirente sem API de consulta não deve ser registrada.
     */
    public function verifyApproved(Payment $payment, Restaurant $restaurant, string $terminalPaymentId): bool;

    /** Nome interno da adquirente — deve coincidir com terminal_acquirer no DB. */
    public function getAcquirerName(): string;
}
