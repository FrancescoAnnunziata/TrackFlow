<?php

namespace App\Models;

use Database\Factories\TravelRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelRate extends Model
{
    /** @use HasFactory<TravelRateFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tipo',
        'from_location',
        'to_location',
        'purpose',
        'km',
    ];

    protected $casts = [
        'km' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
