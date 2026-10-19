<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'restaurant_id', 'name', 'apelido', 'phone', 'email', 'password', 'role', 'is_active',
        'registered_at', 'terms_accepted_at', 'terms_version', 'history_visible_from',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'role'                 => UserRole::class,
            'is_active'            => 'boolean',
            'registered_at'        => 'datetime',
            'terms_accepted_at'    => 'datetime',
            'history_visible_from' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AppSession::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isManager(): bool    { return $this->role === UserRole::MANAGER; }
    public function isWaiter(): bool     { return $this->role === UserRole::WAITER; }
    public function isKitchen(): bool    { return $this->role === UserRole::KITCHEN; }
    public function isClient(): bool     { return $this->role === UserRole::CLIENT; }
    public function isCashier(): bool    { return $this->role === UserRole::CASHIER; }
    public function isSuperAdmin(): bool { return $this->role === UserRole::SUPER_ADMIN; }

    /** Cliente com conta (senha) neste restaurante — não entra mais só pelo telefone. */
    public function hasAccount(): bool
    {
        return $this->isClient() && $this->registered_at !== null;
    }
}
