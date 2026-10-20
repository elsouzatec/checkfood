<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Order\OrderApprovalService;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private OrderApprovalService $approvalService
    ) {}

    /**
     * POST /api/orders
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        // Só a mesa: pedido de entrega tem endpoint próprio (/client/delivery-orders).
        $session = AppSession::atTable()
            ->where('user_id', $user->id)
            ->where('status', SessionStatus::ACTIVE)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Nenhuma sessão ativa encontrada.'], 422);
        }

        $order = Order::create([
            'restaurant_id' => $session->restaurant_id,
            'session_id' => $session->id,
            'table_id' => $session->table_id,
            'user_id' => $user->id,
            'status' => OrderStatus::PENDENTE_VALIDACAO,
        ]);

        foreach ($request->items as $item) {
            $product = Product::find($item['product_id']);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'notes' => $item['notes'] ?? null,
            ]);
        }

        $order->load('items.product.category');

        return response()->json([
            'message' => 'Pedido criado.',
            'order' => $order,
        ], 201);
    }

    /**
     * GET /api/orders/my-session
     */
    public function mySession(Request $request): JsonResponse
    {
        $user = $request->user();

        $session = AppSession::atTable()
            ->where('user_id', $user->id)
            ->where('status', SessionStatus::ACTIVE)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['orders' => []]);
        }

        $orders = Order::where('session_id', $session->id)
            ->with('items.product.category')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /api/orders/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        // Entregas (sem mesa) são aprovadas no caixa, não pelo garçom.
        $orders = Order::where('restaurant_id', $user->restaurant_id)
            ->whereNotNull('table_id')
            ->where('status', OrderStatus::PENDENTE_VALIDACAO)
            ->with(['items.product.category', 'table', 'user'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /api/orders/kitchen?area=cozinha
     */
    public function kitchen(Request $request): JsonResponse
    {
        $user = $request->user();
        $area = $request->query('area', 'cozinha');

        $orders = Order::where('restaurant_id', $user->restaurant_id)
            ->whereIn('status', [
                OrderStatus::CONFIRMADO,
                OrderStatus::PREPARANDO,
                OrderStatus::PRONTO,
                OrderStatus::ENTREGUE,
            ])
            ->whereHas('items.product.category', function ($q) use ($area) {
                $q->where('preparation_area', $area);
            })
            ->with(['items' => function ($q) use ($area) {
                $q->whereHas('product.category', function ($q2) use ($area) {
                    $q2->where('preparation_area', $area);
                });
                $q->with('product.category');
            }, 'table'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /api/orders/ready
     */
    public function ready(Request $request): JsonResponse
    {
        $user = $request->user();

        // Entregas prontas saem pelo caixa, não vão para o garçom levar à mesa.
        $orders = Order::where('restaurant_id', $user->restaurant_id)
            ->whereNotNull('table_id')
            ->where('status', OrderStatus::PRONTO)
            ->with(['items.product.category', 'table'])
            ->orderBy('ready_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /api/orders/{order}
     */
    public function show(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        $order->load('items.product.category', 'table', 'user');

        return response()->json(['order' => $order]);
    }

    /**
     * PATCH /api/orders/{order}/validate
     *
     * Garçom aprova o pedido.
     * Se é a primeira aprovação da sessão, vincula o garçom à sessão.
     * Se itens são de setores diferentes, divide em sub-pedidos.
     * APÓS aprovação → abate estoque dos ingredientes.
     */
    public function validateOrder(Order $order, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if ($order->status !== OrderStatus::PENDENTE_VALIDACAO) {
            return response()->json([
                'message' => 'Transição inválida. Status atual: ' . $order->status->value,
            ], 422);
        }

        $result = $this->approvalService->approve($order, $request->user());
        $count  = count($result['orders']);

        return response()->json([
            'message' => $count > 1
                ? 'Pedido aprovado e separado por setor (' . $count . ' sub-pedidos).'
                : 'Pedido aprovado.',
            'orders'       => $result['orders'],
            'stock_alerts' => $result['stock_alerts'],
        ]);
    }

    /**
     * PATCH /api/orders/{order}/quick-deliver
     */
    public function quickDeliver(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if (!in_array($order->status, [OrderStatus::CONFIRMADO, OrderStatus::PRONTO])) {
            return response()->json([
                'message' => 'Entrega rápida só é possível para pedidos confirmados ou prontos. Status atual: ' . $order->status->value,
            ], 422);
        }

        $order->confirmed_at = $order->confirmed_at ?? now();
        $order->kitchen_started_at = $order->kitchen_started_at ?? now();
        $order->ready_at = $order->ready_at ?? now();
        $order->delivered_at = now();
        $order->status = OrderStatus::ENTREGUE;
        $order->save();

        return response()->json([
            'message' => 'Pedido entregue.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    /**
     * PATCH /api/orders/{order}/start
     */
    public function start(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if (!$order->transitionTo(OrderStatus::PREPARANDO)) {
            return response()->json([
                'message' => 'Transição inválida. Status atual: ' . $order->status->value,
            ], 422);
        }

        return response()->json([
            'message' => 'Preparo iniciado.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    /**
     * PATCH /api/orders/{order}/ready
     */
    public function markReady(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if (!$order->transitionTo(OrderStatus::PRONTO)) {
            return response()->json([
                'message' => 'Transição inválida. Status atual: ' . $order->status->value,
            ], 422);
        }

        return response()->json([
            'message' => 'Pedido pronto.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    /**
     * PATCH /api/orders/{order}/deliver
     */
    public function deliver(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if (!$order->transitionTo(OrderStatus::ENTREGUE)) {
            return response()->json([
                'message' => 'Transição inválida. Status atual: ' . $order->status->value,
            ], 422);
        }

        return response()->json([
            'message' => 'Pedido entregue.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    /**
     * PATCH /api/orders/{order}/pick-and-deliver
     *
     * Atalho para o modo "impressão térmica" — garçom pega da boqueta e entrega
     * sem passar por marcação de "pronto" (já que a cozinha não tem como sinalizar).
     * Aceita pedidos em CONFIRMADO ou PREPARANDO.
     */
    public function pickAndDeliver(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if (!in_array($order->status, [OrderStatus::CONFIRMADO, OrderStatus::PREPARANDO, OrderStatus::PRONTO])) {
            return response()->json([
                'message' => 'Não é possível entregar. Status atual: ' . $order->status->value,
            ], 422);
        }

        $order->ready_at     = $order->ready_at ?? now();
        $order->delivered_at = now();
        $order->status       = OrderStatus::ENTREGUE;
        $order->save();

        return response()->json([
            'message' => 'Pedido entregue.',
            'order'   => $order->fresh('items.product.category'),
        ]);
    }

    /**
     *
     * Se o pedido já havia abatido estoque (CONFIRMADO/PREPARANDO/PRONTO),
     * a reposição é executada. ENTREGUE não repõe (já consumido).
     */
    public function cancel(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order);

        $previousStatus = $order->status;

        if (!$order->transitionTo(OrderStatus::CANCELADO)) {
            return response()->json([
                'message' => 'Não é possível cancelar. Status atual: ' . $previousStatus->value,
            ], 422);
        }

        // Repor estoque se estava em status que já abateu
        $deductedStatuses = [
            OrderStatus::CONFIRMADO,
            OrderStatus::PREPARANDO,
            OrderStatus::PRONTO,
        ];

        if (in_array($previousStatus, $deductedStatuses, true)) {
            $this->stockService->replenishForOrder($order);
        }

        return response()->json([
            'message' => 'Pedido cancelado.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    /**
     * DELETE /api/orders/{order}/items/{orderItem}
     *
     * Só é permitido remover itens em pedidos PENDENTE_VALIDACAO,
     * estado em que o estoque ainda não foi abatido. Sem reposição parcial.
     */
    public function removeItem(Order $order, OrderItem $orderItem): JsonResponse
    {
        $this->authorizeRestaurant($order);

        if ($order->status !== OrderStatus::PENDENTE_VALIDACAO) {
            return response()->json([
                'message' => 'Só é possível remover itens de pedidos pendentes.',
            ], 422);
        }

        if ($orderItem->order_id !== $order->id) {
            return response()->json(['message' => 'Item não pertence a este pedido.'], 404);
        }

        if ($order->items()->count() <= 1) {
            return response()->json([
                'message' => 'Pedido com apenas 1 item. Use cancelar pedido.',
            ], 422);
        }

        $orderItem->delete();

        return response()->json([
            'message' => 'Item removido.',
            'order' => $order->fresh('items.product.category'),
        ]);
    }

    private function authorizeRestaurant(Order $order): void
    {
        $user = request()->user();

        if (!$user || $order->restaurant_id !== $user->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
