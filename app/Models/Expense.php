<?php

namespace App\Models;

use App\Observers\ExpenseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[ObservedBy(ExpenseObserver::class)]
class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'amount',
        'conto',
        'paid_with_personal_card',
        'attachaments',
        'client_id',
        'supplier_id',
        'passive_invoice_id',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'paid_with_personal_card' => 'boolean',
        'attachaments' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Fattura passiva corrispondente alla spesa, se richiesta/ricevuta (il
     * giustificativo fiscale da allegare al report del commercialista).
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }

    /**
     * Rimborso generato automaticamente quando la spesa e' pagata con carta
     * personale (vedi ExpenseObserver).
     */
    public function reimbursement(): HasOne
    {
        return $this->hasOne(Reimbursement::class);
    }

    /**
     * Riconciliazioni bancarie della spesa. La spesa è un documento di costo
     * riconciliabile con un'uscita, come fattura passiva e costo.
     */
    public function reconciliations(): MorphMany
    {
        return $this->morphMany(Reconciliation::class, 'reconcilable');
    }

    /**
     * Importo da coprire con la riconciliazione bancaria (totale della spesa).
     */
    public function total(): float
    {
        return round((float) $this->amount, 2);
    }

    /**
     * Quota già riconciliata con movimenti bancari.
     */
    public function reconciledAmount(): float
    {
        return round((float) $this->reconciliations()->sum('amount'), 2);
    }
}
