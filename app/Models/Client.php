<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'notes',
    ];

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }
}
