<?php

namespace App\Models;

use App\Enums\DeploymentMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'address', 'phone', 'logo_url',
        'deployment_mode', 'frontend_url',
        'wifi_network', 'wifi_password', 'wifi_on_plaque', 'qr_phrase',
        'show_total_during_service', 'show_order_progress',
        'terminal_integration_enabled', 'terminal_acquirer', 'tip_enabled', 'tip_default_percent',
        'allow_client_payment',
        'print_enabled',
        'cashier_mode',
        'mercadopago_access_token', 'mercadopago_webhook_secret', 'mercadopago_public_key',
        'is_active',
    ];

    // Nunca serializados para JSON — credenciais são write-only via endpoint dedicado.
    protected $hidden = [
        'mercadopago_access_token',
        'mercadopago_webhook_secret',
    ];

    protected $casts = [
        'is_active'                    => 'boolean',
        'deployment_mode'              => DeploymentMode::class,
        'wifi_on_plaque'               => 'boolean',
        'show_total_during_service'    => 'boolean',
        'show_order_progress'          => 'boolean',
        'terminal_integration_enabled' => 'boolean',
        'allow_client_payment'         => 'boolean',
        'tip_enabled'                  => 'boolean',
        'tip_default_percent'          => 'decimal:2',
        'print_enabled'                => 'boolean',
        'cashier_mode'                 => 'boolean',
        'mercadopago_access_token'     => 'encrypted',
        'mercadopago_webhook_secret'   => 'encrypted',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * URL base para gerar QR codes e links do tenant. Usa a URL própria do
     * restaurante quando definida (domínio ou IP:porta da intranet); caso
     * contrário cai no padrão da plataforma.
     */
    public function effectiveFrontendUrl(): string
    {
        $base = $this->frontend_url ?: config('app.frontend_url', config('app.url'));

        return rtrim($base, '/');
    }
}
