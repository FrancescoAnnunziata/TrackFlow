<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Costo aziendale senza fattura passiva (commissioni, tasse, bolli, ...).
 * Entra nel conto economico come costo e può essere riconciliato con
 * un'uscita bancaria.
 */
class Costo extends Model
{
    protected $table = 'costi';

    protected $fillable = [
        'date',
        'description',
        'category',
        'amount',
        'vat_amount',
        'supplier_id',
        'reimbursement_id',
        'bank_transaction_id',
        'notes',
        'attachments',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'attachments' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /**
     * Richiesta di rimborso di cui questo costo (km, pasto, ...) fa parte.
     */
    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }

    public function reconciliations(): MorphMany
    {
        return $this->morphMany(Reconciliation::class, 'reconcilable');
    }

    /**
     * Importo da coprire con la riconciliazione bancaria (totale del costo).
     */
    public function total(): float
    {
        return round((float) $this->amount, 2);
    }
}
