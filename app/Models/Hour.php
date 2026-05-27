<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hour extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'minutes',
        'notes',
        'billable',
    ];

    protected $casts = [
        'date' => 'date',
        'minutes' => 'integer',
        'billable' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }
}
