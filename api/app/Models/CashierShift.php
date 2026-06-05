<?php

namespace App\Models;

use App\Enums\CashierShiftStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'cashier_id',
        'opening_amount', 'closing_amount', 'expected_amount', 'difference',
        'notes', 'status', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'          => CashierShiftStatus::class,
            'opening_amount'  => 'float',
            'closing_amount'  => 'float',
            'expected_amount' => 'float',
            'difference'      => 'float',
            'opened_at'       => 'datetime',
            'closed_at'       => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cashier_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === CashierShiftStatus::OPEN;
    }
}
