<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'session_id', 'table_id', 'user_id',
        'status', 'origin', 'customer_document', 'fiscal_status',
        'cancel_reason',
        'confirmed_at', 'kitchen_started_at', 'ready_at',
        'delivered_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'confirmed_at' => 'datetime',
            'kitchen_started_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AppSession::class, 'session_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function total(): float
    {
        return $this->items->sum(function ($item) {
            return $item->unit_price * $item->quantity;
        });
    }

    /**
     * Tenta transicionar para um novo status respeitando a máquina de estados.
     */
    public function transitionTo(OrderStatus $newStatus): bool
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            return false;
        }

        $this->status = $newStatus;

        // Preencher timestamp granular
        match ($newStatus) {
            OrderStatus::CONFIRMADO => $this->confirmed_at = now(),
            OrderStatus::PREPARANDO => $this->kitchen_started_at = now(),
            OrderStatus::PRONTO => $this->ready_at = now(),
            OrderStatus::ENTREGUE => $this->delivered_at = now(),
            OrderStatus::PAGO => $this->paid_at = now(),
            default => null,
        };

        $this->save();
        return true;
    }

    /**
     * Cancelamento forçado (ignora máquina de estados).
     * Usado na liberação manual de mesa.
     */
    public function forceCancel(string $reason): void
    {
        $this->status = OrderStatus::CANCELADO;
        $this->cancel_reason = $reason;
        $this->save();
    }

    public function toArray()
    {
        $data = parent::toArray();
        $data['status'] = $this->status instanceof OrderStatus ? $this->status->value : $this->status;
        $data['total'] = $this->total();
        return $data;
    }
}
