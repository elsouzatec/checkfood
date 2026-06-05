<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::where('restaurant_id', $request->user()->restaurant_id)
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'preparation_area' => 'required|string|in:cozinha,bar,lanchonete',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $data['restaurant_id'] = $request->user()->restaurant_id;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $category = Category::create($data);

        return response()->json(['message' => 'Categoria criada.', 'category' => $category], 201);
    }

    public function show(Category $category, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($category, $request);

        return response()->json(['category' => $category->loadCount('products')]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($category, $request);

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:255',
            'preparation_area' => 'sometimes|string|in:cozinha,bar,lanchonete',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $category->update($data);

        return response()->json(['message' => 'Categoria atualizada.', 'category' => $category->fresh()]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($category, $request);

        if ($category->products()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir categoria com produtos ativos. Desative os produtos primeiro.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Categoria excluída.']);
    }

    private function authorizeRestaurant($model, Request $request): void
    {
        if ($model->restaurant_id !== $request->user()->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
