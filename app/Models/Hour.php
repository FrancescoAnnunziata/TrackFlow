<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hour extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'date',
        'hours',
        'notes',
        'billable',
    ];

    protected $casts = [
        'date' => 'date',
        'billable' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }
}
