<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Fattura passiva (di acquisto). Archivio dei costi importato da Fatture in
 * Cloud. Lo stato di pagamento è derivato dalla riconciliazione bancaria.
 */
class PassiveInvoice extends Model
{
    public const STATUS_PAID = 'paid';

    public const STATUS_NOT_PAID = 'not_paid';

    /** Nota di credito ricevuta (FIC): riduce i costi, non è un documento da pagare. */
    public const TYPE_CREDIT_NOTE = 'passive_credit_note';

    protected $fillable = [
        'supplier_id',
        'fic_document_id',
        'fic_document_token',
        'number',
        'type',
        'document_date',
        'due_date',
        'amount_net',
        'amount_vat',
        'amount_gross',
        'currency',
        'category',
        'payment_status',
        'imported',
        'notes',
        'attachment',
    ];

    protected $casts = [
        'document_date' => 'date',
        'due_date' => 'date',
        'amount_net' => 'decimal:2',
        'amount_vat' => 'decimal:2',
        'amount_gross' => 'decimal:2',
        'imported' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PassiveInvoiceItem::class)->orderBy('sort');
    }

    public function reconciliations(): MorphMany
    {
        return $this->morphMany(Reconciliation::class, 'reconcilable');
    }

    /**
     * Importo totale del documento (lordo). È la cifra che una riconciliazione
     * bancaria deve coprire perché la fattura risulti pagata.
     */
    public function total(): float
    {
        return round((float) $this->amount_gross, 2);
    }

    /**
     * Quota già riconciliata con movimenti bancari.
     */
    public function reconciledAmount(): float
    {
        return round((float) $this->reconciliations()->sum('amount'), 2);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::STATUS_PAID;
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }
}
