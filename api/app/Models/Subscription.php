<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensalidade de um restaurante. Gerenciada exclusivamente pelo super admin
 * (não usa escopo de tenant). O bloqueio por inadimplência reflete em
 * Restaurant::$is_active via o comando billing:check-overdue.
 */
class Subscription extends Model
{
    protected $fillable = [
        'restaurant_id', 'monthly_amount', 'billing_day', 'paid_until', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'billing_day'    => 'integer',
            'paid_until'     => 'date',
            'status'         => SubscriptionStatus::class,
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'restaurant_id', 'restaurant_id')
            ->latest('paid_at');
    }

    public function isOverdue(): bool
    {
        // paid_until é "pago até, inclusive": vencido só quando anterior a hoje.
        return $this->paid_until === null || $this->paid_until->lt(today());
    }
}
