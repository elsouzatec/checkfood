<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $tables = Table::where('restaurant_id', $restaurantId)
            ->withCount([
                'orders as pending_count' => fn($q) => $q->where('status', 'pendente_validacao'),
                'orders as ready_count'   => fn($q) => $q->where('status', 'pronto'),
                'orders as preparing_count' => fn($q) => $q
                    ->where('status', 'preparando')
                    ->whereHas('items.product.category', fn($q2) =>
                        $q2->where('preparation_area', 'cozinha')),
                'orders as received_count' => fn($q) => $q
                    ->where('status', 'confirmado')
                    ->whereHas('items.product.category', fn($q2) =>
                        $q2->where('preparation_area', 'cozinha')),
                'orders as delivered_count' => fn($q) => $q->where('status', 'entregue'),
            ])
            ->orderBy('number')
            ->get();

        // Itens não entregues por mesa (badge numérico)
        $undeliveredItems = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereNotIn('orders.status', ['entregue', 'pago', 'cancelado'])
            ->groupBy('orders.table_id')
            ->select('orders.table_id', DB::raw('SUM(order_items.quantity) as total'))
            ->pluck('total', 'table_id');

        // Valor em aberto por mesa (todos os pedidos não pagos/cancelados)
        $openValues = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereNotIn('orders.status', ['pago', 'cancelado'])
            ->groupBy('orders.table_id')
            ->select('orders.table_id', DB::raw('SUM(order_items.unit_price * order_items.quantity) as total'))
            ->pluck('total', 'table_id');

        // Cliente ativo por mesa (via sessão ativa)
        $activeClients = DB::table('sessions_app')
            ->join('users', 'sessions_app.user_id', '=', 'users.id')
            ->where('sessions_app.restaurant_id', $restaurantId)
            ->where('sessions_app.status', 'active')
            ->whereNotNull('sessions_app.table_id')
            ->select(
                'sessions_app.table_id',
                'users.name as client_name',
                'sessions_app.created_at as session_started_at'
            )
            ->get()
            ->keyBy('table_id');

        $tables->each(function ($table) use ($undeliveredItems, $openValues, $activeClients) {
            $table->undelivered_items   = (int)   ($undeliveredItems[$table->id] ?? 0);
            $table->open_value          = (float)  ($openValues[$table->id] ?? 0);
            $table->qr_url              = $this->buildQrUrl($table);

            $client = $activeClients[$table->id] ?? null;
            $table->client_name         = $client?->client_name;
            $table->session_started_at  = $client ? Carbon::parse($client->session_started_at)->toIso8601String() : null;
        });

        return response()->json(['tables' => $tables]);
    }

    /**
     * GET /api/admin/tables/{table}/detail
     */
    public function detail(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        $session = $table->activeSession();

        if (!$session) {
            return response()->json([
                'table'   => $table,
                'session' => null,
                'client'  => null,
                'orders'  => [],
            ]);
        }

        $session->load('user:id,name,phone');

        $orders = Order::where('session_id', $session->id)
            ->with(['items.product:id,name,price'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'table'   => $table,
            'session' => [
                'id'         => $session->id,
                'started_at' => $session->created_at,
                'status'     => $session->status,
            ],
            'client'  => $session->user ? [
                'name'  => $session->user->name,
                'phone' => $session->user->phone,
            ] : null,
            'orders'  => $orders,
        ]);
    }

    /**
     * POST /api/admin/tables
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'   => 'required|integer|min:1',
            'capacity' => 'nullable|integer|min:1|max:50',
        ]);

        $restaurantId = $request->user()->restaurant_id;

        if (Table::where('restaurant_id', $restaurantId)->where('number', $data['number'])->exists()) {
            return response()->json(['message' => 'Já existe uma mesa com este número.'], 422);
        }

        $table = Table::create([
            'restaurant_id' => $restaurantId,
            'number'        => $data['number'],
            'capacity'      => $data['capacity'] ?? 4,
            'qr_code_token' => Str::uuid()->toString(),
            'status'        => TableStatus::LIVRE,
        ]);

        $table->qr_url = $this->buildQrUrl($table);

        return response()->json(['message' => 'Mesa criada.', 'table' => $table], 201);
    }

    /**
     * POST /api/admin/tables/bulk
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'     => 'required|integer|min:1',
            'to'       => 'required|integer|min:1|gte:from',
            'capacity' => 'nullable|integer|min:1|max:50',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $capacity     = $data['capacity'] ?? 4;
        $created      = 0;
        $skipped      = 0;

        for ($n = $data['from']; $n <= $data['to']; $n++) {
            if (Table::where('restaurant_id', $restaurantId)->where('number', $n)->exists()) {
                $skipped++;
                continue;
            }
            Table::create([
                'restaurant_id' => $restaurantId,
                'number'        => $n,
                'capacity'      => $capacity,
                'qr_code_token' => Str::uuid()->toString(),
                'status'        => TableStatus::LIVRE,
            ]);
            $created++;
        }

        return response()->json([
            'message' => "{$created} mesa(s) criada(s). {$skipped} já existia(m).",
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * PUT /api/admin/tables/{table}
     */
    public function update(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        $data = $request->validate([
            'number'   => 'sometimes|integer|min:1',
            'capacity' => 'sometimes|integer|min:1|max:50',
        ]);

        if (isset($data['number']) && $data['number'] !== $table->number) {
            $exists = Table::where('restaurant_id', $table->restaurant_id)
                ->where('number', $data['number'])
                ->where('id', '!=', $table->id)
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Já existe uma mesa com este número.'], 422);
            }
        }

        $table->update($data);
        $table->qr_url = $this->buildQrUrl($table);

        return response()->json(['message' => 'Mesa atualizada.', 'table' => $table]);
    }

    /**
     * DELETE /api/admin/tables/{table}
     */
    public function destroy(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        if ($table->status === TableStatus::OCUPADA) {
            return response()->json(['message' => 'Não é possível excluir mesa ocupada.'], 422);
        }

        // Remove dependências na ordem correta (FKs sem CASCADE)
        $orderIds = DB::table('orders')->where('table_id', $table->id)->pluck('id');
        if ($orderIds->isNotEmpty()) {
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
        }
        DB::table('waiter_calls')->where('table_id', $table->id)->delete();
        // feedbacks e sessions_app têm ON DELETE SET NULL — resolvidos automaticamente

        $table->delete();

        return response()->json(['message' => 'Mesa excluída.']);
    }

    /**
     * GET /api/admin/tables/{table}/qrcode
     */
    public function qrcode(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        $url = $this->buildQrUrl($table);
        $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($url);

        return response()->json([
            'table_id'     => $table->id,
            'table_number' => $table->number,
            'url'          => $url,
            'qr_image_url' => $qrImageUrl,
            'token'        => $table->qr_code_token,
        ]);
    }

    /**
     * POST /api/admin/tables/{table}/regenerate-token
     */
    public function regenerateToken(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        $table->update(['qr_code_token' => Str::uuid()->toString()]);
        $table->qr_url = $this->buildQrUrl($table);

        return response()->json([
            'message' => 'QR Code regenerado.',
            'table'   => $table,
        ]);
    }

    /**
     * POST /api/admin/tables/{table}/force-release
     */
    public function forceRelease(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($table, $request);

        if ($table->status !== TableStatus::OCUPADA) {
            return response()->json(['message' => 'Mesa não está ocupada.'], 422);
        }

        $session              = $table->activeSession();
        $cancelledConsumed    = 0;
        $cancelledNotConsumed = 0;

        if ($session) {
            $orders = Order::where('session_id', $session->id)
                ->whereNotIn('status', [
                    OrderStatus::PAGO->value,
                    OrderStatus::CANCELADO->value,
                ])
                ->get();

            foreach ($orders as $order) {
                if ($order->delivered_at !== null) {
                    $order->forceCancel('consumido_nao_pago');
                    $cancelledConsumed++;
                } else {
                    $order->forceCancel('nao_consumido');
                    $cancelledNotConsumed++;
                }
            }

            $session->markAsClosed();
        }

        $table->update(['status' => TableStatus::LIVRE]);

        return response()->json([
            'message'               => 'Mesa liberada.',
            'cancelled_consumed'    => $cancelledConsumed,
            'cancelled_not_consumed'=> $cancelledNotConsumed,
        ]);
    }

    private function buildQrUrl(Table $table): string
    {
        return $table->restaurant->effectiveFrontendUrl() . '/mesa/' . $table->qr_code_token;
    }

    private function authorizeRestaurant($model, Request $request): void
    {
        if ($model->restaurant_id !== $request->user()->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
