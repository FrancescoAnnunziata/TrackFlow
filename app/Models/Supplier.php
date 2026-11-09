<?php

namespace App\Models;

use App\Support\Paesi;
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

    /**
     * Il paese si sceglie per codice ISO: il nome leggibile lo segue da sé, così
     * i due campi non possono più divergere (era «Hong Kong» con ISO «IT»).
     */
    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier): void {
            if ($supplier->isDirty('country_iso') && ($nome = Paesi::nome($supplier->country_iso)) !== null) {
                $supplier->country = $nome;
            }
        });
    }

    /** True se il fornitore ha sede fuori dall'Italia. */
    public function isForeign(): bool
    {
        return filled($this->country_iso) && strtoupper($this->country_iso) !== 'IT';
    }

    public function passiveInvoices(): HasMany
    {
        return $this->hasMany(PassiveInvoice::class);
    }
}
