<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hour extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'hours',
        'client_id',
        'notes',
        'billable',
    ];

    protected $casts = [
        'date' => 'date',
        'billable' => 'boolean',
    ];

    public function user():BelongsTo {
        return $this->belongsTo(User::class);
    }
}
