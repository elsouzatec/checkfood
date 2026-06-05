<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'order_id', 'payload', 'status',
        'attempts', 'last_error', 'printed_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'printed_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
