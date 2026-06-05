<?php

namespace App\Models;

use App\Enums\TableStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Table extends Model
{
    use BelongsToRestaurant;

    protected $fillable = [
        'restaurant_id', 'number', 'capacity', 'qr_code_token', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Table $table) {
            if (empty($table->qr_code_token)) {
                $table->qr_code_token = Str::uuid()->toString();
            }
        });
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AppSession::class);
    }

    public function activeSession()
    {
        return $this->sessions()->where('status', 'active')->first();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
