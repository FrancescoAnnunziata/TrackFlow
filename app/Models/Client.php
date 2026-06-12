<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'notes',
        'entity_type',
        'vat_number',
        'tax_code',
        'address_street',
        'address_postal_code',
        'address_city',
        'address_province',
        'country',
        'country_iso',
        'email',
        'certified_email',
        'ei_code',
    ];


    public function hours(): BelongsToMany
    {
        return $this->belongsToMany(Hour::class)->withTimestamps();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
