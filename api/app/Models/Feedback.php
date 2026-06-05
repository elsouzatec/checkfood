<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use BelongsToRestaurant;

    protected $table = 'feedbacks';

    protected $fillable = [
        'restaurant_id', 'session_id', 'user_id', 'table_id',
        'rating', 'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AppSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
