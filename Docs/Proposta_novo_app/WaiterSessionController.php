<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Models\AppSession;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table;
use App\Services\Print\PrintService;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WaiterSessionController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private PrintService $printService
    ) {}

    /**
     * POST /api/waiter/sessions
     *
     * Garçom abre uma sessão para um cliente sem acesso ao app.
     * Vincula o waiter_id imediatamente (sem aguardar primeira aprovação).
     *
     * Body: { table_id, guest_name, guest_phone? }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'table_id'    => 'required|exists:tables,id',
            'guest_name'  => 'required|string|max:100',
            'guest_phone' => 'nullable|string|max:20',
        ]);

        $waiter = $request->user();
        $table  = Table::findOrFail($request->table_id);

        if ($table->restaurant_id !== $waiter->restaurant_id) {
            return response()->json(['message' => 'Mesa não pertence a este restaurante.'], 403);
        }

        // Sessão já ativa — retorna ao invés de duplicar
        $existing = AppSession::where('table_id', $table->id)
            ->where('status', SessionStatus::ACTIVE)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Mesa já possui uma sessão ativa.',
                'session' => $existing,
                'table'   => $table,
            ], 409);
        }

        $phoneDigits = $request->guest_phone
            ? preg_replace('/[^0-9]/', '', $request->guest_phone)
            : null;

        $session = AppSession::create([
            'restaurant_id' => $waiter->restaurant_id,
            'table_id'      => $table->id,
            'user_id'       => null,
            'waiter_id'     => $waiter->id,
            'session_token' => Str::uuid()->toString(),
            'device_id'     => $phoneDigits,
            'guest_name'    => $request->guest_name,
            'status'        => SessionStatus::ACTIVE,
        ]);

        $table->update(['status' => TableStatus::OCUPADA]);

        return response()->json([
            'message' => 'Sessão criada.',
            'session' => $session,
            'table'   => $table,
        ], 201);
    }

    /**
     * POST /api/waiter/sessions/{session}/orders
     *
     * Garçom lança pedido diretamente para a cozinha.
     * Pula pendente_validacao → já cria como CONFIRMADO.
     * Aplica split por setor + abate estoque imediato.
     *
     * Body: { items: [{ product_id, quantity, notes? }] }
     */
    public function storeOrder(AppSession $session, Request $request): JsonResponse
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.notes'      => 'nullable|string|max:255',
        ]);

        $waiter = $request->user();

        if ($session->restaurant_id !== $waiter->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        if ($session->status !== SessionStatus::ACTIVE) {
            return response()->json(['message' => 'Sessão não está ativa.'], 422);
        }

        // Cria pedido principal já confirmado
        $order = Order::create([
            'restaurant_id' => $session->restaurant_id,
            'session_id'    => $session->id,
            'table_id'      => $session->table_id,
            'user_id'       => $waiter->id,
            'status'        => OrderStatus::CONFIRMADO,
            'origin'        => 'garcom',
            'confirmed_at'  => now(),
        ]);

        foreach ($request->items as $item) {
            $product = Product::findOrFail($item['product_id']);
            OrderItem::create([
                'order_id'   => $order->id,
                'product_id' => $product->id,
                'quantity'   => $item['quantity'],
                'unit_price' => $product->price,
                'notes'      => $item['notes'] ?? null,
            ]);
        }

        $order->load('items.product.category');

        // Split por preparation_area
        $groups = $order->items->groupBy(function ($item) {
            return $item->product->category->preparation_area ?? 'cozinha';
        });

        if ($groups->count() <= 1) {
            $alerts = $this->stockService->deductForOrder($order);
            $this->printService->enqueueForOrder($order);

            return response()->json([
                'message' => 'Pedido lançado.',
                'orders'  => [$order->fresh('items.product.category')],
                'stock_alerts' => $alerts,
            ], 201);
        }

        $subOrders = [];
        $allAlerts = [];

        foreach ($groups as $area => $items) {
            $subOrder = Order::create([
                'restaurant_id' => $order->restaurant_id,
                'session_id'    => $order->session_id,
                'table_id'      => $order->table_id,
                'user_id'       => $order->user_id,
                'status'        => OrderStatus::CONFIRMADO,
                'origin'        => 'garcom',
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

            $alerts = $this->stockService->deductForOrder($subOrder);
            $allAlerts = array_merge($allAlerts, $alerts);

            $this->printService->enqueueForOrder($subOrder);

            $subOrders[] = $subOrder;
        }

        $order->items()->delete();
        $order->delete();

        return response()->json([
            'message' => 'Pedido lançado e separado por setor (' . $groups->count() . ' sub-pedidos).',
            'orders'  => $subOrders,
            'stock_alerts' => $allAlerts,
        ], 201);
    }

    /**
     * GET /api/waiter/menu
     *
     * Cardápio ativo do restaurante do garçom autenticado.
     * Cada produto traz stock_status: 'ok' | 'alerta' | 'zerado'
     * (anexado automaticamente via $appends quando ingredients é carregado).
     */
    public function menu(Request $request): JsonResponse
    {
        $categories = Category::where('restaurant_id', $request->user()->restaurant_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['products' => function ($q) {
                $q->where('is_active', true)
                  ->orderBy('sort_order')
                  ->with('ingredients:id,stock_quantity,min_quantity,unit');
            }])
            ->get();

        return response()->json(['categories' => $categories]);
    }

    /**
     * POST /api/waiter/sessions/{session}/forward-cashier
     *
     * Encaminha sessão ao caixa físico:
     *  - Garçom escolhe meio de pagamento sugerido (pix/dinheiro/cartao/maquininha)
     *  - Sessão muda status ACTIVE → AT_CASHIER
     *  - Mesa volta para LIVRE imediatamente (pode receber novo cliente)
     *  - Sessão fica visível na fila do app caixa via at_cashier_at
     *
     * Requer cashier_mode = true no restaurante.
     *
     * Body: { payment_method }
     */
    public function forwardToCashier(AppSession $session, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => 'required|string|in:pix,dinheiro,cartao,maquininha',
        ]);

        $waiter = $request->user();

        if ($session->restaurant_id !== $waiter->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        // Verifica se o restaurante está em modo caixa
        $restaurant = $session->restaurant;
        if (!$restaurant || !$restaurant->cashier_mode) {
            return response()->json([
                'message' => 'Restaurante não está em modo caixa. Use o fluxo de pagamento padrão.',
            ], 422);
        }

        if ($session->status !== SessionStatus::ACTIVE) {
            return response()->json([
                'message' => 'Sessão não está ativa.',
                'current_status' => $session->status->value,
            ], 422);
        }

        // Transição: sessão vai para AT_CASHIER, mesa libera
        $session->forwardToCashier($request->input('payment_method'));

        // Libera a mesa imediatamente para novo cliente
        if ($session->table) {
            $session->table->update(['status' => TableStatus::LIVRE]);
        }

        return response()->json([
            'message' => 'Sessão encaminhada ao caixa. Mesa liberada para novo cliente.',
            'session' => [
                'id'                    => $session->id,
                'status'                => $session->status->value,
                'payment_method_chosen' => $session->payment_method_chosen,
                'at_cashier_at'         => $session->at_cashier_at?->toIso8601String(),
            ],
        ]);
    }
}
