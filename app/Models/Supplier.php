<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornitore: anagrafica speculare al Client, usata dalle fatture passive.
 * Popolata perlopiù dall'import da Fatture in Cloud.
 */
class Supplier extends Model
{
    protected $fillable = [
        'name',
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
        'fic_entity_id',
        'notes',
    ];

    public function passiveInvoices(): HasMany
    {
        return $this->hasMany(PassiveInvoice::class);
    }
}
