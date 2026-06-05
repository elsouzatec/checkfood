<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    /**
     * GET /api/restaurants/by-slug/{slug}
     *
     * Endpoint público — resolve um slug para os dados mínimos necessários
     * antes do login (branding e validação no frontend admin/staff).
     */
    public function bySlug(string $slug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $slug)->first();

        if (!$restaurant || !$restaurant->is_active) {
            return response()->json(['message' => 'Restaurante não encontrado.'], 404);
        }

        return response()->json([
            'restaurant' => [
                'id'       => $restaurant->id,
                'slug'     => $restaurant->slug,
                'name'     => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
            ],
        ]);
    }

    /**
     * GET /api/restaurants/{restaurantId}/menu
     */
    public function index(int $restaurantId): JsonResponse
    {
        $restaurant = Restaurant::find($restaurantId);

        if (!$restaurant || !$restaurant->is_active) {
            return response()->json(['message' => 'Restaurante não encontrado.'], 404);
        }

        $categories = Category::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['products' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->get();

        return response()->json([
            'categories' => $categories,
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
                'show_total_during_service' => $restaurant->show_total_during_service,
                'show_order_progress' => $restaurant->show_order_progress,
            ],
        ]);
    }
}
