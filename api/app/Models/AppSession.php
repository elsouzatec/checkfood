<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Enums\SessionType;
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
        'session_token', 'device_id', 'guest_name', 'status', 'type',
        'payment_method_chosen', 'closed_at', 'at_cashier_at',
        'delivery_address', 'delivery_fee', 'delivery_notes', 'change_for',
        'accepted_at', 'dispatched_at', 'delivered_at',
    ];

    protected $attributes = [
        'type' => 'mesa',
    ];

    protected function casts(): array
    {
        return [
            'status'           => SessionStatus::class,
            'type'             => SessionType::class,
            'closed_at'        => 'datetime',
            'at_cashier_at'    => 'datetime',
            'delivery_address' => 'array',
            'delivery_fee'     => 'decimal:2',
            'change_for'       => 'decimal:2',
            'accepted_at'      => 'datetime',
            'dispatched_at'    => 'datetime',
            'delivered_at'     => 'datetime',
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

    // ─── Tipo ───────────────────────────────────────────────

    public function isDelivery(): bool
    {
        return $this->type === SessionType::ENTREGA;
    }

    /** Só visitas a mesas — a entrega também é sessão, mas sem mesa. */
    public function scopeAtTable($query)
    {
        return $query->where('type', SessionType::MESA->value);
    }

    public function scopeDelivery($query)
    {
        return $query->where('type', SessionType::ENTREGA->value);
    }

    /** Endereço de entrega numa linha (rua, número, complemento — bairro). */
    public function deliveryAddressLine(): ?string
    {
        $a = $this->delivery_address;
        if (!$a) {
            return null;
        }

        $line = trim(($a['street'] ?? '') . ', ' . ($a['number'] ?? ''), ', ');
        if (!empty($a['complement'])) {
            $line .= ' — ' . $a['complement'];
        }
        if (!empty($a['district'])) {
            $line .= ' · ' . $a['district'];
        }

        return $line;
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
