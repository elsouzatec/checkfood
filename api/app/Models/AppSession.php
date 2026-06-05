<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AppSession extends Model
{
    use BelongsToRestaurant;

    protected $table = 'sessions_app';

    protected $fillable = [
        'restaurant_id', 'table_id', 'user_id', 'waiter_id',
        'session_token', 'device_id', 'guest_name', 'status',
        'payment_method_chosen', 'closed_at', 'at_cashier_at',
    ];

    protected function casts(): array
    {
        return [
            'status'        => SessionStatus::class,
            'closed_at'     => 'datetime',
            'at_cashier_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'session_id');
    }

    // ─── Estado helpers ─────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === SessionStatus::ACTIVE;
    }

    public function isClosing(): bool
    {
        return $this->status === SessionStatus::CLOSING;
    }

    public function isAtCashier(): bool
    {
        return $this->status === SessionStatus::AT_CASHIER;
    }

    public function isClosed(): bool
    {
        return $this->status === SessionStatus::CLOSED;
    }

    // ─── Transições ─────────────────────────────────────────

    public function markAsClosed(): void
    {
        $this->update([
            'status'    => SessionStatus::CLOSED,
            'closed_at' => now(),
        ]);
    }

    /**
     * Encaminha sessão ao caixa.
     *
     * Liberada a mesa para novo cliente (mas a sessão atual continua existindo
     * no estado AT_CASHIER até o caixa confirmar o pagamento).
     *
     * @param  string  $paymentMethod  pix|dinheiro|cartao|maquininha
     */
    public function forwardToCashier(string $paymentMethod): void
    {
        $this->update([
            'status'                => SessionStatus::AT_CASHIER,
            'payment_method_chosen' => $paymentMethod,
            'at_cashier_at'         => now(),
        ]);
    }
}
