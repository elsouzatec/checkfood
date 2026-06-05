<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiterCall extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'session_id', 'table_id',
        'client_user_id', 'waiter_id',
        'status', 'expires_at', 'seen_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'seen_at' => 'datetime',
            'dismissed_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }
}
