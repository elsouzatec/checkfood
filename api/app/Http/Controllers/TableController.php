<?php

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableController extends Controller
{
    /**
     * GET /api/tables
     *
     * Contagens separadas para cor da mesa:
     * pending_count    = aguardando aprovação do garçom
     * ready_count      = cozinha finalizou, pronto pra buscar
     * preparing_count  = cozinha está preparando
     * received_count   = confirmado e na fila da cozinha (ainda não começou)
     * delivered_count  = já entregue
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeSessions = ['active', 'closing', 'at_cashier'];

        $tables = Table::where('restaurant_id', $user->restaurant_id)
            ->withCount([
                'orders as pending_count' => function ($q) use ($activeSessions) {
                    $q->where('status', 'pendente_validacao')
                      ->whereHas('session', fn($s) => $s->whereIn('status', $activeSessions));
                },
                'orders as ready_count' => function ($q) use ($activeSessions) {
                    $q->where('status', 'pronto')
                      ->whereHas('session', fn($s) => $s->whereIn('status', $activeSessions));
                },
                // Preparando: cozinha já iniciou
                'orders as preparing_count' => function ($q) use ($activeSessions) {
                    $q->where('status', 'preparando')
                      ->whereHas('session', fn($s) => $s->whereIn('status', $activeSessions))
                      ->whereHas('items.product.category', function ($q2) {
                          $q2->where('preparation_area', 'cozinha');
                      });
                },
                // Recebido: confirmado na fila da cozinha, ainda não iniciou
                'orders as received_count' => function ($q) use ($activeSessions) {
                    $q->where('status', 'confirmado')
                      ->whereHas('session', fn($s) => $s->whereIn('status', $activeSessions))
                      ->whereHas('items.product.category', function ($q2) {
                          $q2->where('preparation_area', 'cozinha');
                      });
                },
                'orders as delivered_count' => function ($q) use ($activeSessions) {
                    $q->where('status', 'entregue')
                      ->whereHas('session', fn($s) => $s->whereIn('status', $activeSessions));
                },
            ])
            ->orderBy('number')
            ->get();

        // Total de itens não entregues por mesa — apenas sessões abertas
        $undeliveredItems = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('sessions_app', 'sessions_app.id', '=', 'orders.session_id')
            ->where('orders.restaurant_id', $user->restaurant_id)
            ->whereNotIn('orders.status', ['entregue', 'pago', 'cancelado'])
            ->whereIn('sessions_app.status', ['active', 'closing', 'at_cashier'])
            ->groupBy('orders.table_id')
            ->select('orders.table_id', DB::raw('SUM(order_items.quantity) as total_items'))
            ->pluck('total_items', 'table_id');

        $tables->each(function ($table) use ($undeliveredItems) {
            $table->undelivered_items = (int) ($undeliveredItems[$table->id] ?? 0);
        });

        return response()->json(['tables' => $tables]);
    }

    /**
     * GET /api/tables/{table}/orders
     */
    public function orders(Table $table): JsonResponse
    {
        $user = request()->user();
        if (!$user || $table->restaurant_id !== $user->restaurant_id) {
            abort(403, 'Acesso negado.');
        }

        // Retorna qualquer sessão não encerrada — active ou closing (PIX pendente/falho).
        // at_cashier e closed são excluídos: a mesa já foi encaminhada ou finalizada.
        $activeSession = AppSession::where('table_id', $table->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->latest()
            ->first();

        // Restringe à sessão ativa para não vazar pedidos de clientes anteriores
        // (sessões AT_CASHIER mantêm orders em "entregue" até o caixa confirmar).
        $orders = $activeSession
            ? Order::where('session_id', $activeSession->id)
                ->whereNotIn('status', ['pago', 'cancelado'])
                ->with(['items.product.category', 'user'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return response()->json([
            'table'   => $table,
            'orders'  => $orders,
            'session' => $activeSession,
        ]);
    }
}
