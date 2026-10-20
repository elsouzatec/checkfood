<?php

namespace App\Services\Delivery;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\SessionType;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Order\OrderApprovalService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pedido para entrega.
 *
 * Cada entrega é uma sessão do tipo 'entrega' (sem mesa) com um pedido
 * origin='delivery'. Ciclo, conduzido pelo caixa:
 *   cliente faz o pedido → aceita (aprova: divide por setor, estoque, impressão)
 *   → cozinha prepara → saiu para entrega → entregue → sessão fechada.
 *
 * Pagamento: PIX pelo app (antecipado — o restaurante só aceita depois de pago)
 * ou dinheiro/cartão na entrega (acertado no caixa ao marcar "entregue").
 * Taxa: pela distância da loja (DeliveryZones) ou fixa, se a loja não tem zonas.
 */
class DeliveryService
{
    /** pix = pelo app, antecipado; dinheiro/cartao = na entrega. */
    public const PAYMENT_METHODS = ['pix', 'dinheiro', 'cartao'];

    public function __construct(
        private OrderApprovalService $approvalService,
        private DeliveryZones $zones,
        private PaymentService $payments,
    ) {}

    /** Cotação para um ponto (o app mostra antes de fechar o pedido). */
    public function quote(Restaurant $restaurant, ?float $lat, ?float $lng): DeliveryQuote
    {
        return $this->zones->quote($restaurant, $lat, $lng);
    }

    /**
     * @param  array{items: array, address: array, location?: ?array, payment_method: string, change_for?: ?float, notes?: ?string}  $data
     * @return array{session: AppSession, payment: ?Payment}
     *
     * @throws DeliveryException
     */
    public function place(User $client, array $data): array
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

        $lat   = isset($data['location']['lat']) ? (float) $data['location']['lat'] : null;
        $lng   = isset($data['location']['lng']) ? (float) $data['location']['lng'] : null;
        $quote = $this->zones->quote($restaurant, $lat, $lng);

        if ($quote->status === 'unknown') {
            throw new DeliveryException('Marque no mapa onde entregar (ou use sua localização).', 422, 'location_required');
        }
        if ($quote->status === 'out_of_range') {
            throw new DeliveryException(
                'Este endereço fica a ' . number_format($quote->distanceKm, 1, ',', '.') . ' km — fora da área de entrega.',
                422,
                'out_of_range'
            );
        }

        $method = $data['payment_method'];

        if ($method === 'pix' && !$restaurant->acceptsOnlinePix()) {
            throw new DeliveryException('PIX pelo app não está disponível neste restaurante.', 422, 'pix_unavailable');
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

        $changeFor = $method === 'dinheiro' && !empty($data['change_for']) ? (float) $data['change_for'] : null;
        $total     = $subtotal + $quote->fee;

        if ($changeFor !== null && $changeFor < $total) {
            throw new DeliveryException('O troco precisa ser para um valor maior que o total do pedido.', 422, 'invalid_change');
        }

        $address = $this->cleanAddress($data['address']);
        if ($lat !== null && $lng !== null) {
            $address['lat'] = $lat;
            $address['lng'] = $lng;
        }

        [$session, $payment] = DB::transaction(function () use ($client, $restaurant, $lines, $address, $method, $changeFor, $data, $quote, $lat, $lng, $total) {
            $session = AppSession::create([
                'restaurant_id'         => $restaurant->id,
                'table_id'              => null,
                'user_id'               => $client->id,
                'session_token'         => Str::uuid()->toString(),
                'device_id'             => preg_replace('/[^0-9]/', '', $client->phone ?? ''),
                'status'                => SessionStatus::ACTIVE,
                'type'                  => SessionType::ENTREGA,
                'delivery_address'      => $address,
                'delivery_fee'          => $quote->fee,
                'delivery_lat'          => $lat,
                'delivery_lng'          => $lng,
                'delivery_distance_km'  => $quote->distanceKm,
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

            $payment = $method === 'pix'
                ? Payment::create([
                    'restaurant_id' => $restaurant->id,
                    'session_id'    => $session->id,
                    'amount'        => round($total, 2),
                    'method'        => 'pix',
                    'status'        => 'pending',
                    'created_by'    => 'client',
                ])
                : null;

            return [$session, $payment];
        });

        // A cobrança no Mercado Pago fica fora da transação: se o gateway
        // falhar, o pedido existe e o cliente gera o PIX de novo pelo app.
        if ($payment) {
            try {
                $payment = $this->payments->payWithPix($payment, $session->load(['restaurant', 'user']));
            } catch (\Throwable $e) {
                Log::warning('Entrega: falha ao gerar PIX', ['session_id' => $session->id, 'error' => $e->getMessage()]);
            }
        }

        return ['session' => $session, 'payment' => $payment];
    }

    /**
     * Restaurante aceita: aprova os pedidos pendentes (setor, estoque, impressão).
     * Entrega com PIX pelo app só é aceita depois de paga.
     *
     * @throws DeliveryException
     */
    public function accept(AppSession $session, User $staff): void
    {
        $this->assertOpen($session);

        if ($session->payment_method_chosen === 'pix' && !$this->isPaid($session)) {
            throw new DeliveryException('Aguardando o cliente pagar o PIX.', 422, 'awaiting_payment');
        }

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
     * Entregue ao cliente. Já pago (PIX pelo app) → encerra; senão vai para o
     * caixa acertar o pagamento recebido na entrega.
     *
     * @throws DeliveryException
     */
    public function markDelivered(AppSession $session): void
    {
        $this->assertOpen($session);

        if (!$session->dispatched_at) {
            throw new DeliveryException('Marque a saída para entrega primeiro.');
        }

        if ($this->isPaid($session)) {
            DB::transaction(function () use ($session) {
                foreach ($this->billableOrders($session) as $order) {
                    $order->update(['status' => OrderStatus::PAGO->value, 'paid_at' => now()]);
                }
                $session->update(['delivered_at' => now()]);
                $session->markAsClosed();
            });

            return;
        }

        $session->update(['delivered_at' => now()]);
        $session->forwardToCashier($session->payment_method_chosen ?? 'dinheiro');
    }

    /**
     * Cancela a entrega (antes do acerto). PIX já pago precisa ser estornado no
     * Mercado Pago pelo restaurante — o painel avisa.
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
            Payment::where('session_id', $session->id)->where('status', 'pending')->update(['status' => 'cancelled']);
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
        if ($session->payment_method_chosen === 'pix' && !$this->isPaid($session)) {
            return 'aguardando_pagamento';
        }

        return 'aguardando';
    }

    /** Total da entrega: itens não cancelados + taxa. */
    public function total(AppSession $session): float
    {
        $items = (float) $this->billableOrders($session)
            ->load('items')
            ->sum(fn ($o) => $o->items->sum(fn ($i) => $i->unit_price * $i->quantity));

        return $items > 0 ? $items + (float) $session->delivery_fee : 0.0;
    }

    /** Pago por completo (PIX pelo app confirmado ou acerto no caixa)? */
    public function isPaid(AppSession $session): bool
    {
        $paid = (float) Payment::where('session_id', $session->id)->where('status', 'paid')->sum('amount');

        return $paid > 0 && $paid + 0.01 >= $this->total($session);
    }

    /** Cobrança PIX pendente (para o app reabrir a tela de pagamento). */
    public function pendingPixPayment(AppSession $session): ?Payment
    {
        return Payment::where('session_id', $session->id)
            ->where('method', 'pix')
            ->where('status', 'pending')
            ->latest()
            ->first();
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
