<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Order\OrderApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedido para entrega.
 *
 * Cada entrega é uma sessão do tipo 'entrega' (sem mesa) com um pedido
 * origin='delivery'. Ciclo, conduzido pelo caixa:
 *   cliente faz o pedido → aceita (aprova: divide por setor, estoque, impressão)
 *   → cozinha prepara → saiu para entrega → entregue (vai para o caixa, AT_CASHIER)
 *   → caixa confirma o pagamento (fluxo de sempre) → sessão fechada.
 * Pagamento é na entrega (dinheiro, cartão ou PIX na maquininha do entregador).
 */
class DeliveryService
{
    /** Formas de pagamento na entrega (mesmos valores do caixa). */
    public const PAYMENT_METHODS = ['dinheiro', 'cartao', 'pix'];

    public function __construct(private OrderApprovalService $approvalService) {}

    /**
     * @param  array{items: array, address: array, payment_method: string, change_for?: ?float, notes?: ?string}  $data
     *
     * @throws DeliveryException
     */
    public function place(User $client, array $data): AppSession
    {
        $restaurant = $client->restaurant;

        if (!$restaurant || !$restaurant->is_active || !$restaurant->delivery_enabled) {
            throw new DeliveryException('Este restaurante não está fazendo entregas no momento.', 422, 'delivery_disabled');
        }

        // Quem está numa mesa pede pela mesa: evita pedir entrega por engano
        // estando no restaurante.
        $atTable = AppSession::atTable()
            ->where('user_id', $client->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->with('table:id,number')
            ->first();

        if ($atTable) {
            throw new DeliveryException(
                'Você está na Mesa ' . ($atTable->table?->number ?? '') . '. Faça o pedido pelo cardápio da mesa.',
                409,
                'has_active_table'
            );
        }

        $lines    = $this->priceItems($restaurant, $data['items']);
        $subtotal = array_sum(array_map(fn ($l) => $l['unit_price'] * $l['quantity'], $lines));

        if ($subtotal + 0.001 < (float) $restaurant->delivery_min_order) {
            throw new DeliveryException(
                'Pedido mínimo para entrega: R$ ' . number_format((float) $restaurant->delivery_min_order, 2, ',', '.') . '.',
                422,
                'below_min_order'
            );
        }

        $method   = $data['payment_method'];
        $changeFor = $method === 'dinheiro' && !empty($data['change_for']) ? (float) $data['change_for'] : null;
        $total    = $subtotal + (float) $restaurant->delivery_fee;

        if ($changeFor !== null && $changeFor < $total) {
            throw new DeliveryException('O troco precisa ser para um valor maior que o total do pedido.', 422, 'invalid_change');
        }

        $address = $this->cleanAddress($data['address']);

        return DB::transaction(function () use ($client, $restaurant, $lines, $address, $method, $changeFor, $data) {
            $session = AppSession::create([
                'restaurant_id'         => $restaurant->id,
                'table_id'              => null,
                'user_id'               => $client->id,
                'session_token'         => Str::uuid()->toString(),
                'device_id'             => preg_replace('/[^0-9]/', '', $client->phone ?? ''),
                'status'                => SessionStatus::ACTIVE,
                'type'                  => SessionType::ENTREGA,
                'delivery_address'      => $address,
                'delivery_fee'          => $restaurant->delivery_fee,
                'delivery_notes'        => $data['notes'] ?? null,
                'payment_method_chosen' => $method,
                'change_for'            => $changeFor,
            ]);

            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'session_id'    => $session->id,
                'table_id'      => null,
                'user_id'       => $client->id,
                'status'        => OrderStatus::PENDENTE_VALIDACAO,
                'origin'        => 'delivery',
            ]);

            foreach ($lines as $line) {
                OrderItem::create($line + ['order_id' => $order->id]);
            }

            $client->update(['last_delivery_address' => $address]);

            return $session;
        });
    }

    /**
     * Restaurante aceita: aprova os pedidos pendentes (setor, estoque, impressão).
     *
     * @throws DeliveryException
     */
    public function accept(AppSession $session, User $staff): void
    {
        $this->assertOpen($session);

        $pending = $session->orders()->where('status', OrderStatus::PENDENTE_VALIDACAO->value)->get();

        if ($pending->isEmpty()) {
            throw new DeliveryException('Esta entrega já foi aceita.');
        }

        DB::transaction(function () use ($session, $pending, $staff) {
            foreach ($pending as $order) {
                $this->approvalService->approve($order, $staff);
            }
            $session->update(['accepted_at' => now()]);
        });
    }

    /**
     * Saiu para entrega: os pedidos são entregues ao entregador.
     *
     * @throws DeliveryException
     */
    public function dispatch(AppSession $session): void
    {
        $this->assertOpen($session);

        if (!$session->accepted_at) {
            throw new DeliveryException('Aceite a entrega antes de despachar.');
        }
        if ($session->dispatched_at) {
            throw new DeliveryException('Esta entrega já saiu.');
        }

        DB::transaction(function () use ($session) {
            foreach ($this->billableOrders($session) as $order) {
                $order->confirmed_at       = $order->confirmed_at ?? now();
                $order->kitchen_started_at = $order->kitchen_started_at ?? now();
                $order->ready_at           = $order->ready_at ?? now();
                $order->delivered_at       = now();
                $order->status             = OrderStatus::ENTREGUE;
                $order->save();
            }
            $session->update(['dispatched_at' => now()]);
        });
    }

    /**
     * Entregue ao cliente: vai para o caixa acertar o pagamento.
     *
     * @throws DeliveryException
     */
    public function markDelivered(AppSession $session): void
    {
        $this->assertOpen($session);

        if (!$session->dispatched_at) {
            throw new DeliveryException('Marque a saída para entrega primeiro.');
        }

        $session->update(['delivered_at' => now()]);
        $session->forwardToCashier($session->payment_method_chosen ?? 'dinheiro');
    }

    /**
     * Cancela a entrega (antes do pagamento).
     *
     * @throws DeliveryException
     */
    public function cancel(AppSession $session, string $reason): void
    {
        $this->assertOpen($session);

        DB::transaction(function () use ($session, $reason) {
            foreach ($session->orders as $order) {
                if ($order->status !== OrderStatus::CANCELADO) {
                    $order->forceCancel($reason);
                }
            }
            $session->markAsClosed();
        });
    }

    /**
     * Etapa vista pelo cliente.
     */
    public function stage(AppSession $session): string
    {
        $orders = $session->relationLoaded('orders') ? $session->orders : $session->orders()->get();

        if ($orders->isNotEmpty() && $orders->every(fn ($o) => $o->status === OrderStatus::CANCELADO)) {
            return 'cancelado';
        }
        if ($session->delivered_at || $session->isClosed()) {
            return 'entregue';
        }
        if ($session->dispatched_at) {
            return 'a_caminho';
        }
        if ($session->accepted_at) {
            return 'em_preparo';
        }

        return 'aguardando';
    }

    // ─── Helpers ────────────────────────────────────────────

    /**
     * Preços vêm do cardápio do restaurante — nunca do app. Produto de outro
     * restaurante ou inativo invalida o pedido.
     *
     * @return array<int, array{product_id: int, quantity: int, unit_price: float, notes: ?string}>
     *
     * @throws DeliveryException
     */
    private function priceItems(Restaurant $restaurant, array $items): array
    {
        $ids      = array_unique(array_map(fn ($i) => (int) $i['product_id'], $items));
        $products = Product::withoutRestaurantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($ids)) {
            throw new DeliveryException('Algum item do pedido não está mais disponível. Atualize o cardápio.', 422, 'unavailable_items');
        }

        return array_map(fn ($i) => [
            'product_id' => (int) $i['product_id'],
            'quantity'   => (int) $i['quantity'],
            'unit_price' => (float) $products[(int) $i['product_id']]->price,
            'notes'      => $i['notes'] ?? null,
        ], $items);
    }

    private function cleanAddress(array $address): array
    {
        return array_map(
            fn ($v) => is_string($v) ? trim($v) : $v,
            array_intersect_key($address, array_flip(['street', 'number', 'complement', 'district', 'reference']))
        );
    }

    private function billableOrders(AppSession $session)
    {
        return $session->orders()->where('status', '!=', OrderStatus::CANCELADO->value)->get();
    }

    /** @throws DeliveryException */
    private function assertOpen(AppSession $session): void
    {
        if (!$session->isDelivery()) {
            throw new DeliveryException('Esta sessão não é uma entrega.', 404);
        }
        if (!$session->isActive()) {
            throw new DeliveryException('Esta entrega já foi encerrada ou está no caixa.');
        }
    }
}
