<?php

namespace App\Services\Print;

use App\Models\Order;
use App\Models\PrintJob;

class PrintService
{
    /**
     * Enfileira um job de impressão para um pedido confirmado.
     *
     * Snapshot dos dados é gravado no payload — assim o cupom continua imprimível
     * mesmo que itens/preços/observações sejam alterados posteriormente.
     */
    public function enqueueForOrder(Order $order): ?PrintJob
    {
        $order->loadMissing(['restaurant', 'table', 'items.product.category', 'user', 'session']);

        if (!$order->restaurant || !$order->restaurant->print_enabled) {
            return null;
        }

        $payload = $this->buildPayload($order);

        return PrintJob::create([
            'restaurant_id' => $order->restaurant_id,
            'order_id'      => $order->id,
            'payload'       => $payload,
            'status'        => 'pending',
        ]);
    }

    /**
     * Monta snapshot completo para o agente formatar em ESC/POS.
     */
    public function buildPayload(Order $order): array
    {
        $area = $order->items->first()?->product?->category?->preparation_area ?? 'cozinha';

        $items = $order->items->map(function ($item) {
            return [
                'quantity' => (int) $item->quantity,
                'name'     => $item->product->name ?? '—',
                'notes'    => $item->notes,
            ];
        })->values()->all();

        // Resolve nomes — quem fez o pedido vs. quem está atendendo
        // - Sessão guest (criada pelo garçom): guest_name é o cliente, user é o próprio garçom
        // - Sessão cliente (QR code):          user é o cliente, waiter_id é o garçom
        $session = $order->session;
        $customerName = null;
        $waiterName   = null;

        if ($session && $session->guest_name) {
            // Sessão guest — cliente sem cadastro
            $customerName = $session->guest_name;
            $waiterName   = $order->user?->name; // o "user" do pedido é o próprio garçom
        } else {
            // Sessão cliente — cliente cadastrado
            $customerName = $order->user?->name;
            // Garçom vinculado à sessão (após primeira aprovação)
            if ($session && $session->waiter_id) {
                $waiter = $session->waiter ?? \App\Models\User::find($session->waiter_id);
                $waiterName = $waiter?->name;
            }
        }

        // Sequencial diário do pedido (#0001, #0002...) por restaurante
        $dailySeq = $this->dailySequence($order);

        return [
            'restaurant_name' => $order->restaurant->name,
            'sector'          => strtoupper($area),
            'order_id'        => $order->id,
            'order_seq'       => $dailySeq,
            'table_number'    => $order->table?->number,
            'waiter_name'     => $waiterName,
            'customer_name'   => $customerName,
            'consumption'     => 'CONSUMIR NO LOCAL', // placeholder — futuro: para viagem
            'created_at'      => optional($order->confirmed_at ?? $order->created_at)->format('d/m/Y H:i'),
            'items'           => $items,
            'item_count'      => count($items),
        ];
    }

    /**
     * Sequencial diário do pedido por restaurante (formato 0001).
     */
    private function dailySequence(Order $order): string
    {
        $position = Order::where('restaurant_id', $order->restaurant_id)
            ->whereDate('created_at', $order->created_at->toDateString())
            ->where('id', '<=', $order->id)
            ->count();

        return str_pad((string) $position, 4, '0', STR_PAD_LEFT);
    }
}
