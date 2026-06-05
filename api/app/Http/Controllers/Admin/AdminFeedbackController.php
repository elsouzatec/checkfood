<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFeedbackController extends Controller
{
    /**
     * GET /api/admin/feedbacks
     * Lista feedbacks com filtros opcionais: ?rating=5&from=2026-04-01&to=2026-04-09
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = Feedback::where('restaurant_id', $restaurantId)
            ->with(['user:id,name,phone', 'table:id,number'])
            ->orderByDesc('created_at');

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $feedbacks = $query->paginate(20);

        // Resumo geral
        $summary = Feedback::where('restaurant_id', $restaurantId)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('ROUND(AVG(rating), 1) as avg_rating'),
                DB::raw('SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive'),
                DB::raw('SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative')
            )
            ->first();

        $distribution = Feedback::where('restaurant_id', $restaurantId)
            ->select('rating', DB::raw('COUNT(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating')
            ->pluck('count', 'rating');

        return response()->json([
            'feedbacks' => $feedbacks,
            'summary' => $summary,
            'distribution' => $distribution,
        ]);
    }
}
