<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga di una fattura passiva. Conserva l'aliquota e l'IVA di riga per il
 * calcolo preciso dell'IVA a credito.
 */
class PassiveInvoiceItem extends Model
{
    protected $fillable = [
        'passive_invoice_id',
        'name',
        'description',
        'qty',
        'measure',
        'net_price',
        'vat_value',
        'vat_amount',
        'category',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'net_price' => 'decimal:2',
        'vat_value' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class);
    }

    public function lineTotal(): float
    {
        return round((float) $this->qty * (float) $this->net_price, 2);
    }
}
