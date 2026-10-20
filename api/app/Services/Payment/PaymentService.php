<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Models\AppSession;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private PaymentGatewayFactory $gatewayFactory
    ) {}

    /**
     * Calcula o total da sessão (pedidos não cancelados + taxa de entrega).
     */
    public function calculateSessionTotal(AppSession $session): float
    {
        $items = (float) DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.session_id', $session->id)
            ->where('orders.status', '!=', 'cancelado')
            ->sum(DB::raw('order_items.unit_price * order_items.quantity'));

        // Entrega: taxa entra na conta (só se sobrou item a pagar).
        return $items > 0 ? $items + (float) $session->delivery_fee : 0.0;
    }

    /**
     * Inicia o processo de pagamento:
     * 1. Calcula total
     * 2. Cria payment record (pending)
     * 3. Seta sessão como CLOSING
     * Idempotente: se sessão já está closing, retorna o payment existente.
     */
    public function initiate(AppSession $session, string $createdBy): Payment
    {
        // Idempotência: já existe pagamento pendente?
        $existing = Payment::where('session_id', $session->id)
            ->whereIn('status', ['pending'])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $total = $this->calculateSessionTotal($session);

        if ($total <= 0) {
            throw new \RuntimeException('Não há itens a pagar nesta sessão.');
        }

        return DB::transaction(function () use ($session, $total, $createdBy) {
            $payment = Payment::create([
                'restaurant_id' => $session->restaurant_id,
                'session_id'    => $session->id,
                'amount'        => $total,
                'status'        => 'pending',
                'created_by'    => $createdBy,
            ]);

            $session->update(['status' => SessionStatus::CLOSING]);

            return $payment;
        });
    }

    /**
     * Processa pagamento Pix via gateway.
     * Salva QR code no payment record e retorna os dados para o frontend.
     */
    public function payWithPix(Payment $payment, AppSession $session): Payment
    {
        if ($payment->isPaid()) {
            throw new \RuntimeException('Este pagamento já foi confirmado.');
        }

        // Se já tem QR code gerado (e não expirou), reaproveita
        if ($payment->qr_code && $payment->expires_at && $payment->expires_at->isFuture()) {
            return $payment;
        }

        $table      = $session->table;
        $restaurant = $session->restaurant;
        $client     = $session->user;

        $gateway = $this->gatewayFactory->forRestaurant($restaurant);

        $chargeData = $gateway->createPixCharge($payment, [
            'table_number'    => $table->number,
            'restaurant_name' => $restaurant->name,
            'client_name'     => $client->name ?? 'Cliente',
        ]);

        $payment->update([
            'method'             => 'pix',
            'gateway'            => 'mercadopago',
            'gateway_payment_id' => $chargeData['gateway_payment_id'],
            'gateway_status'     => $chargeData['gateway_status'],
            'qr_code'            => $chargeData['qr_code'],
            'qr_code_base64'     => $chargeData['qr_code_base64'],
            'ticket_url'         => $chargeData['ticket_url'],
            'expires_at'         => $chargeData['expires_at'],
        ]);

        return $payment->fresh();
    }

    /**
     * Pagamento manual (dinheiro ou cartão):
     * Confirma imediatamente sem gateway.
     */
    public function payManual(Payment $payment, string $method): Payment
    {
        if ($payment->isPaid()) {
            throw new \RuntimeException('Este pagamento já foi confirmado.');
        }

        DB::transaction(function () use ($payment, $method) {
            $payment->update([
                'method'  => $method,
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            $this->closeSession($payment->session);
        });

        return $payment->fresh();
    }

    /**
     * Confirmação via webhook (Pix aprovado pelo gateway).
     */
    public function confirmFromGateway(Payment $payment): void
    {
        if ($payment->isPaid()) {
            return; // idempotente
        }

        DB::transaction(function () use ($payment) {
            $payment->update([
                'status'         => 'paid',
                'gateway_status' => 'approved',
                'paid_at'        => now(),
            ]);

            $this->closeSession($payment->session);
        });

        Log::info('Payment confirmed via webhook', ['payment_id' => $payment->id]);
    }

    /**
     * Encerra a sessão e marca todos os pedidos como PAGO.
     */
    private function closeSession(AppSession $session): void
    {
        // Marca pedidos entregues (ou confirmados/prontos) como pagos
        $session->orders()
            ->whereNotIn('status', ['cancelado', 'pago'])
            ->each(function ($order) {
                $order->update([
                    'status'  => OrderStatus::PAGO->value,
                    'paid_at' => now(),
                ]);
            });

        $session->markAsClosed();

        $session->table->update(['status' => TableStatus::LIVRE->value]);
    }
}
