<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Print\PrintService;
use App\Services\Stock\StockService;

/**
 * Aprovação de pedido: confirma, divide por setor (cozinha/bar), abate o
 * estoque e enfileira a impressão. Usada pelo garçom (pedido da mesa) e pelo
 * caixa (pedido de entrega).
 */
class OrderApprovalService
{
    public function __construct(
        private StockService $stockService,
        private PrintService $printService
    ) {}

    /**
     * O pedido precisa estar em PENDENTE_VALIDACAO (o chamador confere).
     *
     * @return array{orders: Order[], stock_alerts: array}
     */
    public function approve(Order $order, User $approver): array
    {
        // Na mesa, quem aprova o primeiro pedido vira o garçom da sessão.
        $session = $order->session;
        if ($session && !$session->isDelivery() && !$session->waiter_id) {
            $session->update(['waiter_id' => $approver->id]);
        }

        $order->load('items.product.category');

        // Agrupar itens por preparation_area
        $groups = $order->items->groupBy(function ($item) {
            return $item->product->category->preparation_area ?? 'cozinha';
        });

        if ($groups->count() <= 1) {
            $order->transitionTo(OrderStatus::CONFIRMADO);

            $alerts = $this->stockService->deductForOrder($order);
            $this->printService->enqueueForOrder($order);

            return [
                'orders'       => [$order->fresh('items.product.category')],
                'stock_alerts' => $alerts,
            ];
        }

        // Múltiplos setores — dividir em sub-pedidos
        $subOrders = [];
        $allAlerts = [];

        foreach ($groups as $area => $items) {
            $subOrder = Order::create([
                'restaurant_id' => $order->restaurant_id,
                'session_id'    => $order->session_id,
                'table_id'      => $order->table_id,
                'user_id'       => $order->user_id,
                'status'        => OrderStatus::CONFIRMADO,
                'origin'        => $order->origin,
                'confirmed_at'  => now(),
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id'   => $subOrder->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'notes'      => $item->notes,
                ]);
            }

            $subOrder->load('items.product.category');

            $allAlerts = array_merge($allAlerts, $this->stockService->deductForOrder($subOrder));

            // Impressão por setor (1 cupom por sub-pedido)
            $this->printService->enqueueForOrder($subOrder);

            $subOrders[] = $subOrder;
        }

        $order->items()->delete();
        $order->delete();

        return [
            'orders'       => $subOrders,
            'stock_alerts' => $allAlerts,
        ];
    }
}
