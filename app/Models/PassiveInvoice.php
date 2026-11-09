<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'original_currency',
        'original_amount',
        'category',
        'payment_status',
        'reimbursement_id',
        'imported',
        'notes',
        'attachment',
        'integration_type',
        'integrates_number',
        'integrates_date',
        'integrates_id',
    ];

    protected $casts = [
        'document_date' => 'date',
        'due_date' => 'date',
        'amount_net' => 'decimal:2',
        'amount_vat' => 'decimal:2',
        'amount_gross' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'integrates_date' => 'date',
        'imported' => 'boolean',
    ];

    /** Nome dello scope che tiene fuori le integrazioni dell'esterometro. */
    public const SCOPE_SENZA_INTEGRAZIONI = 'senza_integrazioni';

    /**
     * Le integrazioni dell'esterometro (TD17/18/19) non sono fatture da pagare né
     * costi: sono la dichiarazione dell'IVA su una fattura estera già registrata.
     * Fuori da tutto per default (elenchi, riconciliazione, registro, IVA,
     * conto economico, assistente); si vedono solo attaccate alla fattura che
     * integrano, o chiedendole esplicitamente con withoutGlobalScope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(self::SCOPE_SENZA_INTEGRAZIONI, fn (Builder $q) => $q->whereNull($q->qualifyColumn('integration_type')));
    }

    /** Query che include anche le integrazioni. */
    public static function conIntegrazioni(): Builder
    {
        return static::withoutGlobalScope(self::SCOPE_SENZA_INTEGRAZIONI);
    }

    public function isIntegration(): bool
    {
        return filled($this->integration_type);
    }

    /** La fattura estera che questa integrazione integra. */
    public function integrates(): BelongsTo
    {
        return $this->belongsTo(self::class, 'integrates_id');
    }

    /** Le integrazioni emesse dal commercialista per questa fattura estera. */
    public function integrations(): HasMany
    {
        return $this->hasMany(self::class, 'integrates_id')
            ->withoutGlobalScope(self::SCOPE_SENZA_INTEGRAZIONI);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Richiesta di rimborso che copre questa fattura (anticipata dal dipendente
     * col conto personale): quando il bonifico chiude il rimborso, la fattura
     * risulta pagata.
     */
    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
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

    public function isPaidViaReimbursement(): bool
    {
        return $this->reimbursement_id !== null;
    }
}
