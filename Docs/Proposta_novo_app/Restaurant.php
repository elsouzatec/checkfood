<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    protected $fillable = [
        'name', 'slug', 'address', 'phone', 'logo_url',
        'wifi_network', 'wifi_password', 'wifi_on_plaque', 'qr_phrase',
        'show_total_during_service', 'show_order_progress',
        'terminal_integration_enabled', 'terminal_acquirer', 'tip_enabled', 'tip_default_percent',
        'allow_client_payment',
        'print_enabled',
        'cashier_mode',
        'is_active',
    ];

    protected $casts = [
        'is_active'                    => 'boolean',
        'wifi_on_plaque'               => 'boolean',
        'show_total_during_service'    => 'boolean',
        'show_order_progress'          => 'boolean',
        'terminal_integration_enabled' => 'boolean',
        'allow_client_payment'         => 'boolean',
        'tip_enabled'                  => 'boolean',
        'tip_default_percent'          => 'decimal:2',
        'print_enabled'                => 'boolean',
        'cashier_mode'                 => 'boolean',
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
}
