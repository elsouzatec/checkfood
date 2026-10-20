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
        'delivery_enabled', 'delivery_fee', 'delivery_min_order', 'delivery_eta_minutes', 'delivery_area_note',
        'latitude', 'longitude', 'delivery_free_km', 'delivery_max_km',
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
        'delivery_enabled'             => 'boolean',
        'delivery_fee'                 => 'decimal:2',
        'delivery_min_order'           => 'decimal:2',
        'delivery_eta_minutes'         => 'integer',
        'latitude'                     => 'float',
        'longitude'                    => 'float',
        'delivery_free_km'             => 'float',
        'delivery_max_km'              => 'float',
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

    /** Regras de entrega expostas ao app do cliente. */
    public function deliveryConfig(): array
    {
        return [
            'enabled'     => (bool) $this->delivery_enabled,
            'fee'         => (float) $this->delivery_fee,
            'min_order'   => (float) $this->delivery_min_order,
            'eta_minutes' => $this->delivery_eta_minutes,
            'area_note'   => $this->delivery_area_note,
            'zones'       => $this->deliveryZones(),
            'pix_enabled' => $this->acceptsOnlinePix(),
        ];
    }

    /**
     * Zonas por distância — null quando a loja não marcou a localização ou o
     * raio máximo (aí vale a taxa fixa em qualquer endereço).
     *
     * @return array{lat: float, lng: float, free_km: float, max_km: float, fee: float}|null
     */
    public function deliveryZones(): ?array
    {
        if ($this->latitude === null || $this->longitude === null || !$this->delivery_max_km) {
            return null;
        }

        return [
            'lat'     => (float) $this->latitude,
            'lng'     => (float) $this->longitude,
            'free_km' => (float) ($this->delivery_free_km ?? 0),
            'max_km'  => (float) $this->delivery_max_km,
            'fee'     => (float) $this->delivery_fee,
        ];
    }

    /** PIX pelo app depende só do Mercado Pago configurado no restaurante. */
    public function acceptsOnlinePix(): bool
    {
        return !empty($this->mercadopago_access_token);
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
