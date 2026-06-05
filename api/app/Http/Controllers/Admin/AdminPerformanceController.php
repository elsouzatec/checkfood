<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard de produtividade e eficiência.
 *
 * Métrica central: lead_time = delivered_at - created_at (jornada completa do
 * cliente). O admin define um SLA em minutos e o sistema reporta % de
 * cumprimento + distribuição.
 */
class AdminPerformanceController extends Controller
{
    /**
     * GET /api/admin/performance?period=today|7d|30d|custom&sla_minutes=30
     *                          &start_date=2026-04-01&end_date=2026-04-26
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'period'       => 'sometimes|string|in:today,7d,30d,custom',
            'sla_minutes'  => 'sometimes|integer|min:5|max:240',
            'start_date'   => 'sometimes|date',
            'end_date'     => 'sometimes|date|after_or_equal:start_date',
        ]);

        $period      = $request->query('period', 'today');
        $slaMinutes  = (int) $request->query('sla_minutes', 30);
        $restaurantId = $request->user()->restaurant_id;

        [$start, $end, $granularity] = $this->resolvePeriod(
            $period,
            $request->query('start_date'),
            $request->query('end_date')
        );

        // Todos os pedidos do período (inclui cancelados pra calcular taxa)
        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$start, $end])
            ->get([
                'id', 'status', 'created_at',
                'confirmed_at', 'kitchen_started_at', 'ready_at', 'delivered_at',
            ]);

        $kpis     = $this->computeKpis($orders, $slaMinutes);
        $timeline = $this->computeTimeline($orders, $slaMinutes, $granularity, $start, $end);
        $buckets  = $this->computeDistribution($orders, $slaMinutes);

        return response()->json([
            'period' => [
                'type'        => $period,
                'start'       => $start->toIso8601String(),
                'end'         => $end->toIso8601String(),
                'granularity' => $granularity,
            ],
            'sla_minutes' => $slaMinutes,
            'kpis'        => $kpis,
            'timeline'    => $timeline,
            'buckets'     => $buckets,
        ]);
    }

    /**
     * Resolve range de datas + granularidade do eixo X dos gráficos.
     */
    private function resolvePeriod(string $period, ?string $startDate, ?string $endDate): array
    {
        $tz = config('app.timezone');

        switch ($period) {
            case 'today':
                $start = Carbon::today($tz);
                $end   = Carbon::now($tz);
                $granularity = 'hour';
                break;

            case '7d':
                $start = Carbon::today($tz)->subDays(6);
                $end   = Carbon::now($tz);
                $granularity = 'day';
                break;

            case '30d':
                $start = Carbon::today($tz)->subDays(29);
                $end   = Carbon::now($tz);
                $granularity = 'day';
                break;

            case 'custom':
                $start = Carbon::parse($startDate ?: 'today', $tz)->startOfDay();
                $end   = Carbon::parse($endDate   ?: 'today', $tz)->endOfDay();
                $rangeDays = $start->diffInDays($end);
                $granularity = $rangeDays > 2 ? 'day' : 'hour';
                break;

            default:
                $start = Carbon::today($tz);
                $end   = Carbon::now($tz);
                $granularity = 'hour';
        }

        return [$start, $end, $granularity];
    }

    /**
     * KPIs principais.
     */
    private function computeKpis($orders, int $slaMinutes): array
    {
        $total       = $orders->count();
        // Um pedido é considerado entregue se delivered_at foi populado, independente
        // do status atual ser 'entregue' ou 'pago' (status avança após entrega).
        $delivered   = $orders->filter(fn ($o) => $o->delivered_at !== null);
        $cancelled   = $orders->filter(fn ($o) =>
            $this->statusValue($o->status) === 'cancelado'
        )->count();
        $deliveredCt = $delivered->count();

        // Recorte operacional: separa entregues que ainda estão em serviço
        // (consumindo ou aguardando pagamento) dos já fechados.
        $stillInService = $delivered->filter(fn ($o) =>
            $this->statusValue($o->status) === 'entregue'
        )->count();
        $alreadyPaid = $delivered->filter(fn ($o) =>
            $this->statusValue($o->status) === 'pago'
        )->count();

        $leadTimes = $delivered->map(function ($order) {
            return $order->created_at->diffInSeconds($order->delivered_at);
        });

        // Cumprimento do SLA
        $slaSeconds = $slaMinutes * 60;
        $withinSla  = $leadTimes->filter(fn ($s) => $s <= $slaSeconds)->count();
        $slaRate    = $deliveredCt > 0
            ? round(($withinSla / $deliveredCt) * 100, 1)
            : null;

        // Tempo médio e mediana
        $avgLead    = $leadTimes->isNotEmpty() ? round($leadTimes->avg() / 60, 1) : null;
        $medianLead = $leadTimes->isNotEmpty()
            ? round($this->median($leadTimes->all()) / 60, 1)
            : null;

        // ── Decomposição do tempo médio em 4 etapas ──
        // 1. Aprovação:  created_at → confirmed_at  (responsável: garçom)
        // 2. Fila:       confirmed_at → kitchen_started_at  (responsável: cozinha)
        // 3. Preparo:    kitchen_started_at → ready_at  (responsável: cozinha)
        // 4. Entrega:    ready_at → delivered_at  (responsável: garçom)
        // Quando kitchen_started_at é nulo (cozinha pulou etapa), trata como
        // fila zero — preparo absorve o tempo total da cozinha.
        $approvalTimes = $delivered
            ->filter(fn ($o) => $o->confirmed_at !== null)
            ->map(fn ($o) => $o->created_at->diffInSeconds($o->confirmed_at));
        $queueTimes = $delivered
            ->filter(fn ($o) => $o->confirmed_at !== null && $o->kitchen_started_at !== null)
            ->map(fn ($o) => $o->confirmed_at->diffInSeconds($o->kitchen_started_at));
        $prepTimes = $delivered
            ->filter(fn ($o) => $o->ready_at !== null)
            ->map(function ($o) {
                $start = $o->kitchen_started_at ?? $o->confirmed_at ?? $o->created_at;
                return $start->diffInSeconds($o->ready_at);
            });
        $waiterPickupTimes = $delivered
            ->filter(fn ($o) => $o->ready_at !== null)
            ->map(fn ($o) => $o->ready_at->diffInSeconds($o->delivered_at));

        // Taxa de cancelamento
        $cancelRate = $total > 0
            ? round(($cancelled / $total) * 100, 1)
            : null;

        return [
            'total_orders'        => $total,
            'delivered_orders'    => $deliveredCt,
            'in_service_orders'   => $stillInService,    // entregues, ainda na mesa (status='entregue')
            'paid_orders'         => $alreadyPaid,        // entregues e ciclo fechado (status='pago')
            'cancelled_orders'    => $cancelled,
            'cancel_rate'         => $cancelRate,         // %
            'sla_compliance'      => $slaRate,            // % dentro do SLA
            'avg_lead_minutes'    => $avgLead,
            'median_lead_minutes' => $medianLead,
            'within_sla_count'    => $withinSla,
            'breached_sla_count'  => $deliveredCt - $withinSla,

            // Decomposição (em minutos, médias)
            'breakdown' => [
                'approval'      => $this->avgInMinutes($approvalTimes),
                'queue'         => $this->avgInMinutes($queueTimes),
                'preparation'   => $this->avgInMinutes($prepTimes),
                'waiter_pickup' => $this->avgInMinutes($waiterPickupTimes),
            ],

            // KPI dedicado: lacuna do garçom — quanto o pedido pronto fica
            // esperando ser entregue. Isolamento da produtividade do salão.
            'waiter_gap_minutes' => $this->avgInMinutes($waiterPickupTimes),
            'waiter_gap_median'  => $waiterPickupTimes->isNotEmpty()
                ? round($this->median($waiterPickupTimes->all()) / 60, 1)
                : null,

            // ── Termômetros temporais ──
            //
            // Conceito: SLA é responsabilidade da cozinha (estágio dominante).
            // O salão só impacta quando cria anomalia (lacuna grande).
            //
            // - Cozinha: % das jornadas dentro do SLA (resultado entregue ao cliente)
            // - Salão:   % das entregas com lacuna aceitável (não comprometedora)
            // - Fora do SLA: dos atrasos, quantos vieram do salão (lacuna > 5min)
            'efficiency' => $this->computeThermometers($delivered, $slaMinutes),
        ];
    }

    /**
     * Termômetros temporais.
     *
     * GAP_THRESHOLD = 5min é o limite entre lacuna aceitável e comprometedora.
     */
    private function computeThermometers($delivered, int $slaMinutes): array
    {
        $gapThresholdSec = 5 * 60;
        $slaSec          = $slaMinutes * 60;

        if ($delivered->isEmpty()) {
            return [
                'kitchen'        => null,
                'hall'           => null,
                'breach_by_hall' => null,
                'kitchen_breakdown' => [
                    'queue_avg'   => null,
                    'prep_avg'    => null,
                    'queue_share' => null,
                    'prep_share'  => null,
                ],
            ];
        }

        // ── Cozinha: % entregues no SLA ──
        $deliveredCt = $delivered->count();
        $withinSla = $delivered->filter(fn ($o) =>
            $o->created_at->diffInSeconds($o->delivered_at) <= $slaSec
        )->count();
        $kitchenPct = round(($withinSla / $deliveredCt) * 100, 1);

        // ── Salão: % das entregas com lacuna aceitável ──
        // Considera apenas pedidos que chegaram a ter ready_at marcado.
        $withReady = $delivered->filter(fn ($o) => $o->ready_at !== null);
        $hallPct = null;
        if ($withReady->isNotEmpty()) {
            $hallOk = $withReady->filter(fn ($o) =>
                $o->ready_at->diffInSeconds($o->delivered_at) <= $gapThresholdSec
            )->count();
            $hallPct = round(($hallOk / $withReady->count()) * 100, 1);
        }

        // ── Fora do SLA por culpa do salão ──
        // Dos pedidos que estouraram o SLA, quantos têm lacuna > threshold?
        // Esses são os "atrasos que poderiam ter sido evitados pelo salão".
        $breached = $delivered->filter(fn ($o) =>
            $o->created_at->diffInSeconds($o->delivered_at) > $slaSec
        );
        $breachByHallPct = null;
        if ($breached->isNotEmpty()) {
            $breachByHall = $breached->filter(fn ($o) =>
                $o->ready_at !== null
                && $o->ready_at->diffInSeconds($o->delivered_at) > $gapThresholdSec
            )->count();
            $breachByHallPct = round(($breachByHall / $breached->count()) * 100, 1);
        }

        // ── Decomposição da Cozinha: fila vs preparo ──
        // Para identificar se gargalo é volume (fila) ou velocidade (preparo).
        $queueTimes = $delivered
            ->filter(fn ($o) => $o->confirmed_at !== null && $o->kitchen_started_at !== null)
            ->map(fn ($o) => $o->confirmed_at->diffInSeconds($o->kitchen_started_at));
        $prepTimes = $delivered
            ->filter(fn ($o) => $o->ready_at !== null)
            ->map(function ($o) {
                // Quando kitchen_started_at é nulo (cozinha pulou etapa),
                // trata como fila zero — preparo absorve o tempo total.
                $start = $o->kitchen_started_at ?? $o->confirmed_at ?? $o->created_at;
                return $start->diffInSeconds($o->ready_at);
            });

        $queueAvg = $queueTimes->isNotEmpty() ? round($queueTimes->avg() / 60, 1) : null;
        $prepAvg  = $prepTimes->isNotEmpty()  ? round($prepTimes->avg()  / 60, 1) : null;

        // Proporção fila/preparo no tempo total da cozinha (% de cada componente)
        $queueShare = null;
        $prepShare  = null;
        if ($queueAvg !== null && $prepAvg !== null) {
            $sum = $queueAvg + $prepAvg;
            if ($sum > 0) {
                $queueShare = round(($queueAvg / $sum) * 100, 1);
                $prepShare  = round(($prepAvg  / $sum) * 100, 1);
            }
        }

        return [
            'kitchen'        => $kitchenPct,
            'hall'           => $hallPct,
            'breach_by_hall' => $breachByHallPct,
            'kitchen_breakdown' => [
                'queue_avg'   => $queueAvg,
                'prep_avg'    => $prepAvg,
                'queue_share' => $queueShare,
                'prep_share'  => $prepShare,
            ],
        ];
    }

    private function avgInMinutes($collection): ?float
    {
        return $collection->isNotEmpty() ? round($collection->avg() / 60, 1) : null;
    }

    /**
     * Extrai valor string do status, lidando tanto com enum tipado quanto com string crua.
     */
    private function statusValue($status): string
    {
        if (is_object($status) && property_exists($status, 'value')) {
            return $status->value;
        }
        return (string) $status;
    }

    /**
     * Timeline para gráfico principal: para cada bucket de tempo (hora ou dia)
     * retorna volume + cumprimento do SLA + tempo médio.
     */
    private function computeTimeline($orders, int $slaMinutes, string $granularity, Carbon $start, Carbon $end): array
    {
        $slaSeconds = $slaMinutes * 60;
        $tz = config('app.timezone');

        // Agrupa por bucket conforme granularidade
        $grouped = $orders->groupBy(function ($order) use ($granularity, $tz) {
            $dt = $order->created_at->copy()->setTimezone($tz);
            return $granularity === 'hour'
                ? $dt->format('Y-m-d H:00')
                : $dt->format('Y-m-d');
        });

        $buckets = [];
        $cursor  = $start->copy();

        if ($granularity === 'hour') {
            $cursor->minute(0)->second(0);
            while ($cursor <= $end) {
                $buckets[$cursor->format('Y-m-d H:00')] = $cursor->copy();
                $cursor->addHour();
            }
        } else {
            $cursor->startOfDay();
            $endDay = $end->copy()->startOfDay();
            while ($cursor <= $endDay) {
                $buckets[$cursor->format('Y-m-d')] = $cursor->copy();
                $cursor->addDay();
            }
        }

        $points = [];
        foreach ($buckets as $key => $dt) {
            $bucketOrders = $grouped->get($key, collect());
            // delivered_at é a verdade — status pode ter avançado para 'pago'
            $delivered = $bucketOrders->filter(fn ($o) => $o->delivered_at !== null);
            // Pedidos prontos pela cozinha — capacidade real, antes da etapa do garçom
            $ready     = $bucketOrders->filter(fn ($o) => $o->ready_at !== null);

            $leadTimes = $delivered->map(fn ($o) => $o->created_at->diffInSeconds($o->delivered_at));
            $withinSla = $leadTimes->filter(fn ($s) => $s <= $slaSeconds)->count();

            $points[] = [
                'label'           => $granularity === 'hour'
                    ? $dt->format('H\h')
                    : $dt->format('d/m'),
                'iso'             => $dt->toIso8601String(),
                'total'           => $bucketOrders->count(),
                'ready'           => $ready->count(),
                'delivered'       => $delivered->count(),
                'sla_compliance'  => $delivered->count() > 0
                    ? round(($withinSla / $delivered->count()) * 100, 1)
                    : null,
                'avg_lead_minutes' => $leadTimes->isNotEmpty()
                    ? round($leadTimes->avg() / 60, 1)
                    : null,
            ];
        }

        return $points;
    }

    /**
     * Distribuição de lead times em buckets fixos relativos ao SLA.
     */
    private function computeDistribution($orders, int $slaMinutes): array
    {
        $delivered = $orders->filter(fn ($o) => $o->delivered_at !== null);
        $leadMinutes = $delivered->map(fn ($o) => $o->created_at->diffInMinutes($o->delivered_at));

        $sla = $slaMinutes;

        // Buckets relativos ao SLA: <50%, 50-80%, 80-100%, 100-150%, >150%
        $thresholds = [
            ['label' => '0–'        . round($sla * 0.5)  . 'min', 'max' => $sla * 0.5,  'category' => 'excellent'],
            ['label' => round($sla * 0.5)  . '–' . round($sla * 0.8) . 'min', 'max' => $sla * 0.8,  'category' => 'good'],
            ['label' => round($sla * 0.8)  . '–' . $sla . 'min', 'max' => $sla,         'category' => 'warning'],
            ['label' => $sla . '–' . round($sla * 1.5) . 'min', 'max' => $sla * 1.5,    'category' => 'breach'],
            ['label' => '> ' . round($sla * 1.5) . 'min', 'max' => INF,                  'category' => 'critical'],
        ];

        $buckets = [];
        $remaining = $leadMinutes->all();

        foreach ($thresholds as $t) {
            $count = collect($remaining)->filter(fn ($m) => $m <= $t['max'])->count();
            $buckets[] = [
                'label'    => $t['label'],
                'category' => $t['category'],
                'count'    => $count,
            ];
            $remaining = collect($remaining)->filter(fn ($m) => $m > $t['max'])->all();
        }

        return $buckets;
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) return 0;
        $mid = (int) floor($n / 2);
        return $n % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }
}
