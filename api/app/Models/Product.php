<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'category_id', 'name', 'description',
        'price', 'image_url', 'is_active', 'is_composite', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_composite' => 'boolean',
    ];

    /**
     * stock_status é incluído na serialização sempre que a relação 'ingredients'
     * estiver carregada. Quando não está, o accessor retorna 'ok'.
     */
    protected $appends = ['stock_status'];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Ficha técnica: ingredientes vinculados ao produto com quantidade e unidade
     * de consumo.
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }

    /**
     * Recalcula is_composite com base na presença (ou ausência) de ingredientes
     * vinculados. Salva a alteração quando há mudança de estado.
     */
    public function syncCompositeFlag(): void
    {
        $shouldBeComposite = $this->ingredients()->exists();
        if ($this->is_composite !== $shouldBeComposite) {
            $this->is_composite = $shouldBeComposite;
            $this->save();
        }
    }

    /**
     * Status agregado de estoque do produto:
     *  - 'ok'      : sem ficha técnica OU todos ingredientes acima do mínimo
     *  - 'alerta'  : algum ingrediente em alerta (mas todos > 0)
     *  - 'zerado'  : algum ingrediente <= 0
     *
     * Requer que a relação 'ingredients' tenha sido carregada.
     */
    public function getStockStatusAttribute(): string
    {
        if (!$this->relationLoaded('ingredients') || $this->ingredients->isEmpty()) {
            return 'ok';
        }

        $hasZero = false;
        $hasAlert = false;

        foreach ($this->ingredients as $ing) {
            if ((float) $ing->stock_quantity <= 0) {
                $hasZero = true;
            } elseif ((float) $ing->stock_quantity <= (float) $ing->min_quantity) {
                $hasAlert = true;
            }
        }

        if ($hasZero) return 'zerado';
        if ($hasAlert) return 'alerta';
        return 'ok';
    }
}
