<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
