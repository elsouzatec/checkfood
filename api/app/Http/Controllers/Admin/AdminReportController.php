<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminReportController extends Controller
{
    /**
     * GET /api/admin/reports/today
     */
    public function today(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;
        $today        = Carbon::today();

        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', '!=', 'cancelado')
            ->get();

        $totalOrders    = $orders->count();
        $deliveredOrders= $orders->where('status', 'entregue')->count();
        $paidOrders     = $orders->where('status', 'pago')->count();
        $pendingOrders  = $totalOrders - $deliveredOrders - $paidOrders;

        $revenue = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereDate('orders.created_at', $today)
            ->where('orders.status', '!=', 'cancelado')
            ->sum(DB::raw('order_items.unit_price * order_items.quantity'));

        $topProducts = DB::table('order_items')
            ->join('orders',   'orders.id',   '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereDate('orders.created_at', $today)
            ->where('orders.status', '!=', 'cancelado')
            ->select(
                'products.id', 'products.name', 'products.price as current_price',
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.unit_price * order_items.quantity) as total_revenue')
            )
            ->groupBy('products.id', 'products.name', 'products.price')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        // Breakdown por método de pagamento
        $paymentBreakdown = $this->paymentBreakdown($restaurantId, $today, $today);

        return response()->json([
            'date'    => $today->toDateString(),
            'orders'  => [
                'total'     => $totalOrders,
                'delivered' => $deliveredOrders,
                'paid'      => $paidOrders,
                'pending'   => $pendingOrders,
            ],
            'revenue'           => round((float) $revenue, 2),
            'top_products'      => $topProducts,
            'payment_breakdown' => $paymentBreakdown,
        ]);
    }

    /**
     * GET /api/admin/reports/period?start=&end=
     */
    public function period(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end'   => 'required|date|after_or_equal:start',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $start        = Carbon::parse($request->start)->startOfDay();
        $end          = Carbon::parse($request->end)->endOfDay();

        $dailyRevenue = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelado')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('SUM(order_items.unit_price * order_items.quantity) as revenue')
            )
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->orderBy('date')
            ->get();

        $topProducts = DB::table('order_items')
            ->join('orders',   'orders.id',   '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelado')
            ->select(
                'products.id', 'products.name',
                DB::raw('SUM(order_items.quantity) as total_qty'),
                DB::raw('SUM(order_items.unit_price * order_items.quantity) as total_revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        $totalRevenue = $dailyRevenue->sum('revenue');
        $totalOrders  = $dailyRevenue->sum('total_orders');

        // Breakdown por método de pagamento
        $paymentBreakdown = $this->paymentBreakdown($restaurantId, $start, $end, true);

        return response()->json([
            'start'             => $request->start,
            'end'               => $request->end,
            'total_orders'      => $totalOrders,
            'total_revenue'     => round((float) $totalRevenue, 2),
            'daily'             => $dailyRevenue,
            'top_products'      => $topProducts,
            'payment_breakdown' => $paymentBreakdown,
        ]);
    }

    /**
     * Retorna breakdown de pagamentos por método (pix, dinheiro, cartao, maquininha).
     * Usa a tabela payments (apenas status=paid).
     */
    private function paymentBreakdown(int $restaurantId, Carbon $start, Carbon $end, bool $isRange = false): array
    {
        $query = DB::table('payments')
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'paid');

        if ($isRange) {
            $query->whereBetween('paid_at', [$start, $end]);
        } else {
            $query->whereDate('paid_at', $start);
        }

        $rows = $query
            ->select(
                DB::raw('COALESCE(method, "nao_informado") as method'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('method')
            ->get();

        // Normaliza para labels amigáveis
        $labels = [
            'pix'          => ['label' => 'Pix',        'icon' => '📱'],
            'dinheiro'     => ['label' => 'Dinheiro',   'icon' => '💵'],
            'cartao'       => ['label' => 'Cartão',     'icon' => '💳'],
            'maquininha'   => ['label' => 'Maquininha', 'icon' => '🖥️'],
            'nao_informado'=> ['label' => 'Outro',      'icon' => '❓'],
        ];

        $totalPaid = $rows->sum('total');

        return $rows->map(function ($row) use ($labels, $totalPaid) {
            $meta = $labels[$row->method] ?? ['label' => $row->method, 'icon' => '💰'];
            return [
                'method'     => $row->method,
                'label'      => $meta['label'],
                'icon'       => $meta['icon'],
                'count'      => (int) $row->count,
                'total'      => round((float) $row->total, 2),
                'percentage' => $totalPaid > 0
                    ? round(($row->total / $totalPaid) * 100, 1)
                    : 0,
            ];
        })->sortByDesc('total')->values()->toArray();
    }
}
