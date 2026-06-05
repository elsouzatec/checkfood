<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'restaurant_id', 'amount', 'paid_at', 'period_start', 'period_end',
        'method', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'paid_at'      => 'date',
            'period_start' => 'date',
            'period_end'   => 'date',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
