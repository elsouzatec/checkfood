<?php

namespace App\Services\Stock;

use App\Models\Ingredient;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Serviço central de estoque por ingredientes.
 *
 * Regras de unidade:
 *  - Estoque cadastrado em kg → consumo em g (÷1000)
 *  - Estoque cadastrado em l  → consumo em ml (÷1000)
 *  - Estoque cadastrado em un → consumo em un (1:1)
 */
class StockService
{
    /**
     * Abate do estoque os ingredientes consumidos pelos itens do pedido.
     * Retorna lista de ingredientes que ficaram em alerta ou zerados.
     */
    public function deductForOrder(Order $order): array
    {
        $alerts = [];

        DB::transaction(function () use ($order, &$alerts) {
            $order->load('items.product.ingredients');

            foreach ($order->items as $item) {
                $product = $item->product;
                if (!$product || $product->ingredients->isEmpty()) {
                    continue;
                }

                foreach ($product->ingredients as $ingredient) {
                    $consumption = $this->computeConsumption(
                        (float) $ingredient->pivot->quantity,
                        $ingredient->pivot->unit,
                        $ingredient->unit
                    ) * (int) $item->quantity;

                    // Lock pessimista para evitar race conditions
                    $fresh = Ingredient::lockForUpdate()->find($ingredient->id);
                    $fresh->stock_quantity = max(0, (float) $fresh->stock_quantity - $consumption);
                    $fresh->save();

                    if ((float) $fresh->stock_quantity <= (float) $fresh->min_quantity) {
                        $alerts[$fresh->id] = $fresh;
                    }
                }
            }
        });

        return array_values($alerts);
    }

    /**
     * Repõe ao estoque os ingredientes consumidos pelos itens do pedido.
     * Usado em cancelamentos.
     */
    public function replenishForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->load('items.product.ingredients');

            foreach ($order->items as $item) {
                $product = $item->product;
                if (!$product || $product->ingredients->isEmpty()) {
                    continue;
                }

                foreach ($product->ingredients as $ingredient) {
                    $consumption = $this->computeConsumption(
                        (float) $ingredient->pivot->quantity,
                        $ingredient->pivot->unit,
                        $ingredient->unit
                    ) * (int) $item->quantity;

                    $fresh = Ingredient::lockForUpdate()->find($ingredient->id);
                    $fresh->stock_quantity = (float) $fresh->stock_quantity + $consumption;
                    $fresh->save();
                }
            }
        });
    }

    /**
     * Converte uma quantidade da unidade de consumo para a unidade de estoque.
     */
    public function computeConsumption(float $quantity, string $consumptionUnit, string $stockUnit): float
    {
        if ($consumptionUnit === 'g'  && $stockUnit === 'kg') return $quantity / 1000;
        if ($consumptionUnit === 'ml' && $stockUnit === 'l')  return $quantity / 1000;
        if ($consumptionUnit === 'un' && $stockUnit === 'un') return $quantity;

        // Caso de cadastro inconsistente — não converte
        return $quantity;
    }

    /**
     * Valida se o par de unidades é compatível.
     */
    public static function isUnitPairValid(string $stockUnit, string $consumptionUnit): bool
    {
        return match ($stockUnit) {
            'kg' => $consumptionUnit === 'g',
            'l'  => $consumptionUnit === 'ml',
            'un' => $consumptionUnit === 'un',
            default => false,
        };
    }
}
