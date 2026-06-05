<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminIngredientController extends Controller
{
    /**
     * GET /api/admin/ingredients
     */
    public function index(Request $request): JsonResponse
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('name')
            ->get();

        return response()->json(['ingredients' => $ingredients]);
    }

    /**
     * GET /api/admin/ingredients/alerts
     * Lista apenas os ingredientes em alerta ou zerados (badge do menu).
     */
    public function alerts(Request $request): JsonResponse
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->whereColumn('stock_quantity', '<=', 'min_quantity')
            ->orderBy('stock_quantity')
            ->get();

        return response()->json([
            'count'       => $ingredients->count(),
            'ingredients' => $ingredients,
        ]);
    }

    /**
     * POST /api/admin/ingredients
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'unit'           => 'required|string|in:kg,l,un',
            'stock_quantity' => 'required|numeric|min:0',
            'min_quantity'   => 'required|numeric|min:0',
        ]);

        $data['restaurant_id'] = $request->user()->restaurant_id;

        $ingredient = Ingredient::create($data);

        return response()->json([
            'message'    => 'Ingrediente cadastrado.',
            'ingredient' => $ingredient,
        ], 201);
    }

    /**
     * PUT /api/admin/ingredients/{ingredient}
     */
    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($ingredient, $request);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:120',
            'unit'           => 'sometimes|string|in:kg,l,un',
            'stock_quantity' => 'sometimes|numeric|min:0',
            'min_quantity'   => 'sometimes|numeric|min:0',
        ]);

        // Se a unidade mudou, validar se há produtos vinculados (impacta abate futuro)
        if (isset($data['unit']) && $data['unit'] !== $ingredient->unit) {
            if ($ingredient->products()->exists()) {
                return response()->json([
                    'message' => 'Não é possível alterar a unidade — há produtos com ficha técnica vinculada. Remova os vínculos antes.',
                ], 422);
            }
        }

        $ingredient->update($data);

        return response()->json([
            'message'    => 'Ingrediente atualizado.',
            'ingredient' => $ingredient->fresh(),
        ]);
    }

    /**
     * DELETE /api/admin/ingredients/{ingredient}
     */
    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($ingredient, $request);

        // Cascata via FK retira os vínculos automaticamente; mas avisamos antes
        $linkedProducts = $ingredient->products()->count();

        $ingredient->delete();

        return response()->json([
            'message' => 'Ingrediente excluído.' . ($linkedProducts ? " ($linkedProducts produto(s) tiveram a ficha técnica afetada)" : ''),
        ]);
    }

    /**
     * GET /api/admin/products/{product}/ingredients
     */
    public function listProductIngredients(Product $product, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        $product->load('ingredients');

        return response()->json([
            'ingredients' => $product->ingredients,
        ]);
    }

    /**
     * POST /api/admin/products/{product}/ingredients
     * Body: { ingredient_id, quantity, unit }
     */
    public function attachIngredient(Product $product, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        $data = $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'quantity'      => 'required|numeric|min:0.001',
            'unit'          => 'required|string|in:g,ml,un',
        ]);

        $ingredient = Ingredient::findOrFail($data['ingredient_id']);

        if ($ingredient->restaurant_id !== $request->user()->restaurant_id) {
            return response()->json(['message' => 'Ingrediente não pertence a este restaurante.'], 403);
        }

        if (!StockService::isUnitPairValid($ingredient->unit, $data['unit'])) {
            return response()->json([
                'message' => "Unidade incompatível: estoque em '{$ingredient->unit}' exige consumo em '" .
                    ($ingredient->unit === 'kg' ? 'g' : ($ingredient->unit === 'l' ? 'ml' : 'un')) . "'.",
            ], 422);
        }

        $product->ingredients()->syncWithoutDetaching([
            $data['ingredient_id'] => [
                'quantity' => $data['quantity'],
                'unit'     => $data['unit'],
            ],
        ]);

        $product->syncCompositeFlag();

        return response()->json([
            'message'     => 'Ingrediente vinculado.',
            'ingredients' => $product->fresh()->load('ingredients')->ingredients,
        ]);
    }

    /**
     * DELETE /api/admin/products/{product}/ingredients/{ingredient}
     */
    public function detachIngredient(Product $product, Ingredient $ingredient, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        $product->ingredients()->detach($ingredient->id);
        $product->syncCompositeFlag();

        return response()->json([
            'message'     => 'Ingrediente desvinculado.',
            'ingredients' => $product->fresh()->load('ingredients')->ingredients,
        ]);
    }

    private function authorizeRestaurant($model, Request $request): void
    {
        if ($model->restaurant_id !== $request->user()->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
