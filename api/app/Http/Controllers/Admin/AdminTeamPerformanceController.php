<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard de produtividade da equipe (garçons).
 *
 * Lógica de atribuição:
 *  - Pedidos com origin='garcom' (sessão guest): garçom = order.user_id
 *  - Outros pedidos: garçom = session.waiter_id (vinculado na 1ª aprovação)
 *  - Sem nenhum dos dois: agrupa em "Sem atribuição"
 *
 * Faturamento por mesa: soma de order_items.unit_price * quantity dos pedidos
 * não-cancelados das mesas atendidas pelo garçom (mesma convenção do
 * AdminReportController). Isso captura tudo o que foi consumido nas mesas
 * dele, mesmo se o pagamento ainda não foi registrado.
 */
class AdminTeamPerformanceController extends Controller
{
    /**
     * GET /api/admin/team-performance?period=today|7d|30d|custom
     *                               &sla_minutes=30
     *                               &start_date=...&end_date=...
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period'      => 'sometimes|string|in:today,7d,30d,custom',
            'sla_minutes' => 'sometimes|integer|min:5|max:240',
            'start_date'  => 'sometimes|date',
            'end_date'    => 'sometimes|date|after_or_equal:start_date',
        ]);

        $period       = $request->query('period', 'today');
        $slaMinutes   = (int) $request->query('sla_minutes', 30);
        $restaurantId = $request->user()->restaurant_id;

        [$start, $end] = $this->resolvePeriod(
            $period,
            $request->query('start_date'),
            $request->query('end_date')
        );

        // Busca todos os pedidos do período com info necessária para atribuição
        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->with([
                'session:id,waiter_id,table_id',
                'items:id,order_id,unit_price,quantity',
            ])
            ->get([
                'id', 'session_id', 'user_id', 'origin', 'status', 'table_id',
                'created_at', 'confirmed_at', 'kitchen_started_at',
                'ready_at', 'delivered_at',
            ]);

        // Atribui cada pedido ao garçom responsável (ou null para "Sem atribuição")
        $orders = $orders->map(function ($order) {
            $waiterId = null;
            if ($order->origin === 'garcom') {
                $waiterId = $order->user_id;
            } elseif ($order->session && $order->session->waiter_id) {
                $waiterId = $order->session->waiter_id;
            }
            $order->assigned_waiter_id = $waiterId;
            return $order;
        });

        // Agrupa por garçom
        $byWaiter = $orders->groupBy('assigned_waiter_id');

        // Carrega nomes dos garçons que aparecem nos resultados
        $waiterIds = $byWaiter->keys()->filter()->values();
        $waiters   = User::whereIn('id', $waiterIds)->get(['id', 'name'])->keyBy('id');

        // Monta linha por garçom
        $rows = [];
        foreach ($byWaiter as $waiterId => $waiterOrders) {
            $rows[] = $this->computeWaiterStats(
                $waiterId,
                $waiters[$waiterId] ?? null,
                $waiterOrders,
                $slaMinutes
            );
        }

        // Ordena: garçons com pedidos primeiro, "Sem atribuição" no final
        usort($rows, function ($a, $b) {
            if ($a['waiter_id'] === null) return 1;
            if ($b['waiter_id'] === null) return -1;
            return $b['delivered_orders'] <=> $a['delivered_orders'];
        });

        // KPIs agregados (toda a equipe)
        $teamKpis = $this->computeTeamKpis($orders, $slaMinutes);

        return response()->json([
            'period' => [
                'type'  => $period,
                'start' => $start->toIso8601String(),
                'end'   => $end->toIso8601String(),
            ],
            'sla_minutes' => $slaMinutes,
            'team_kpis'   => $teamKpis,
            'waiters'     => $rows,
        ]);
    }

    /**
     * Calcula KPIs de um único garçom.
     */
    private function computeWaiterStats($waiterId, $waiter, $waiterOrders, int $slaMinutes): array
    {
        $delivered = $waiterOrders->filter(fn ($o) => $o->delivered_at !== null);
        $cancelled = $waiterOrders->filter(fn ($o) =>
            $this->statusValue($o->status) === 'cancelado'
        );

        // Mesas únicas atendidas
        $tablesServed = $waiterOrders->pluck('table_id')->filter()->unique()->count();

        // Faturamento das mesas atendidas (todos os pedidos não-cancelados desse garçom)
        $revenue = $waiterOrders
            ->filter(fn ($o) => $this->statusValue($o->status) !== 'cancelado')
            ->reduce(function ($sum, $o) {
                if (!$o->relationLoaded('items')) return $sum;
                return $sum + $o->items->sum(fn ($i) => $i->unit_price * $i->quantity);
            }, 0);

        // Lacuna do garçom: tempo prato pronto até entregue
        $gaps = $delivered
            ->filter(fn ($o) => $o->ready_at !== null)
            ->map(fn ($o) => $o->ready_at->diffInSeconds($o->delivered_at));
        $gapAvg = $gaps->isNotEmpty() ? round($gaps->avg() / 60, 1) : null;

        // Tempo de aprovação
        $approvals = $waiterOrders
            ->filter(fn ($o) => $o->confirmed_at !== null)
            ->map(fn ($o) => $o->created_at->diffInSeconds($o->confirmed_at));
        $approvalAvg = $approvals->isNotEmpty() ? round($approvals->avg() / 60, 1) : null;

        // SLA das mesas atendidas
        $slaSeconds = $slaMinutes * 60;
        $leadTimes  = $delivered->map(fn ($o) => $o->created_at->diffInSeconds($o->delivered_at));
        $withinSla  = $leadTimes->filter(fn ($s) => $s <= $slaSeconds)->count();
        $slaRate    = $delivered->count() > 0
            ? round(($withinSla / $delivered->count()) * 100, 1)
            : null;

        $totalLeadAvg = $leadTimes->isNotEmpty() ? round($leadTimes->avg() / 60, 1) : null;

        return [
            'waiter_id'        => $waiterId,
            'waiter_name'      => $waiterId === null
                ? 'Sem atribuição'
                : ($waiter?->name ?? 'Garçom #' . $waiterId),
            'is_orphan'        => $waiterId === null,
            'tables_served'    => $tablesServed,
            'total_orders'     => $waiterOrders->count(),
            'delivered_orders' => $delivered->count(),
            'cancelled_orders' => $cancelled->count(),
            'revenue'          => round((float) $revenue, 2),
            'gap_avg_minutes'  => $gapAvg,
            'approval_avg_minutes' => $approvalAvg,
            'total_lead_avg_minutes' => $totalLeadAvg,
            'sla_compliance'   => $slaRate,
            'within_sla_count' => $withinSla,
        ];
    }

    /**
     * KPIs agregados de toda a equipe.
     */
    private function computeTeamKpis($orders, int $slaMinutes): array
    {
        $delivered = $orders->filter(fn ($o) => $o->delivered_at !== null);
        $assigned  = $orders->filter(fn ($o) => $o->assigned_waiter_id !== null);
        $orphan    = $orders->filter(fn ($o) => $o->assigned_waiter_id === null);

        $activeWaiters = $orders
            ->pluck('assigned_waiter_id')
            ->filter()
            ->unique()
            ->count();

        $gaps = $delivered
            ->filter(fn ($o) => $o->ready_at !== null)
            ->map(fn ($o) => $o->ready_at->diffInSeconds($o->delivered_at));
        $teamGapAvg = $gaps->isNotEmpty() ? round($gaps->avg() / 60, 1) : null;

        return [
            'active_waiters'  => $activeWaiters,
            'orphan_orders'   => $orphan->count(),
            'orphan_rate'     => $orders->count() > 0
                ? round(($orphan->count() / $orders->count()) * 100, 1)
                : null,
            'team_gap_avg'    => $teamGapAvg,
        ];
    }

    private function resolvePeriod(string $period, ?string $startDate, ?string $endDate): array
    {
        $tz = config('app.timezone');

        switch ($period) {
            case 'today':
                return [Carbon::today($tz), Carbon::now($tz)];
            case '7d':
                return [Carbon::today($tz)->subDays(6), Carbon::now($tz)];
            case '30d':
                return [Carbon::today($tz)->subDays(29), Carbon::now($tz)];
            case 'custom':
                $start = Carbon::parse($startDate ?: 'today', $tz)->startOfDay();
                $end   = Carbon::parse($endDate   ?: 'today', $tz)->endOfDay();
                return [$start, $end];
            default:
                return [Carbon::today($tz), Carbon::now($tz)];
        }
    }

    private function statusValue($status): string
    {
        if (is_object($status) && property_exists($status, 'value')) {
            return $status->value;
        }
        return (string) $status;
    }
}
