<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga di una fattura. `vat_kind` distingue le righe standard (IVA piena) da
 * quelle art. 15 (rimborsi esclusi da IVA). `line_kind` traccia la natura
 * (consulenza, anticipo, conguaglio, extra, spese) per presentazione/debug.
 */
class InvoiceItem extends Model
{
    public const VAT_STANDARD = 'standard';

    public const VAT_ART15 = 'art15';

    protected $fillable = [
        'invoice_id',
        'name',
        'description',
        'qty',
        'measure',
        'net_price',
        'vat_kind',
        'line_kind',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'net_price' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Totale netto della riga (qty × prezzo unitario).
     */
    public function lineTotal(): float
    {
        return round((float) $this->qty * (float) $this->net_price, 2);
    }

    public function isArt15(): bool
    {
        return $this->vat_kind === self::VAT_ART15;
    }
}
