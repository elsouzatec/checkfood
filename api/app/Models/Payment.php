<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'session_id', 'amount', 'method', 'status',
        'created_by', 'cashier_shift_id',
        'gateway', 'gateway_payment_id', 'gateway_status',
        'qr_code', 'qr_code_base64', 'ticket_url', 'expires_at',
        'customer_document', 'fiscal_status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'float',
            'expires_at' => 'datetime',
            'paid_at'    => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AppSession::class, 'session_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isPaid(): bool     { return $this->status === 'paid'; }
    public function isExpired(): bool  { return $this->status === 'expired'; }
}
