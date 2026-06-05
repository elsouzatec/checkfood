<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::where('restaurant_id', $request->user()->restaurant_id)
            ->with('category:id,name,preparation_area')
            ->orderBy('sort_order');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $products = $query->get();

        return response()->json(['products' => $products]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
            'is_composite' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $data['restaurant_id'] = $request->user()->restaurant_id;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $product = Product::create($data);

        return response()->json([
            'message' => 'Produto criado.',
            'product' => $product->load('category:id,name,preparation_area'),
        ], 201);
    }

    public function show(Product $product, Request $request): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        return response()->json([
            'product' => $product->load('category:id,name,preparation_area'),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        $data = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'name' => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:500',
            'price' => 'sometimes|numeric|min:0',
            'is_active' => 'boolean',
            'is_composite' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $product->update($data);

        return response()->json([
            'message' => 'Produto atualizado.',
            'product' => $product->fresh()->load('category:id,name,preparation_area'),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        // Soft-disable: se tem pedidos vinculados, apenas desativa
        if ($product->is_active) {
            $product->update(['is_active' => false]);
            return response()->json(['message' => 'Produto desativado (possui histórico de pedidos).']);
        }

        $product->delete();
        return response()->json(['message' => 'Produto excluído.']);
    }

    /**
     * POST /api/admin/products/{product}/image
     * Upload de imagem do produto → storage/app/public/products/
     */
    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($product, $request);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        // Remover imagem antiga se existir
        if ($product->image_url) {
            $oldPath = str_replace('/storage/', '', $product->image_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('image')->store('products', 'public');
        $product->update(['image_url' => '/storage/' . $path]);

        return response()->json([
            'message' => 'Imagem atualizada.',
            'image_url' => $product->image_url,
        ]);
    }

    private function authorizeRestaurant($model, Request $request): void
    {
        if ($model->restaurant_id !== $request->user()->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
