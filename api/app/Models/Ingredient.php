<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'name', 'unit', 'stock_quantity', 'min_quantity',
    ];

    protected $casts = [
        'stock_quantity' => 'decimal:3',
        'min_quantity'   => 'decimal:3',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }

    /**
     * Status do ingrediente:
     *  - 'zerado'  : stock_quantity <= 0
     *  - 'alerta'  : stock_quantity <= min_quantity (e > 0)
     *  - 'ok'      : caso contrário
     */
    public function getStockStatusAttribute(): string
    {
        if ((float) $this->stock_quantity <= 0) {
            return 'zerado';
        }
        if ((float) $this->stock_quantity <= (float) $this->min_quantity) {
            return 'alerta';
        }
        return 'ok';
    }

    public function toArray()
    {
        $data = parent::toArray();
        $data['stock_status'] = $this->stock_status;
        return $data;
    }
}
