<?php

namespace App\Models;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Reimbursement extends Model
{
    /** @use HasFactory<\Database\Factories\ReimbursementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'expense_id',
        'type',
        'status',
        'date',
        'amount',
        'notes',
        'attachments',
    ];

    protected $casts = [
        'type' => ReimbursementType::class,
        'status' => ReimbursementStatus::class,
        'date' => 'date',
        'amount' => 'decimal:2',
        'attachments' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Spesa d'origine, se il rimborso e' stato generato automaticamente da una
     * spesa pagata con carta personale.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * True se il rimborso e' sincronizzato da una spesa (non modificabile a
     * mano nei campi importo/data/tipo: la fonte e' la Spesa).
     */
    public function isFromExpense(): bool
    {
        return $this->expense_id !== null;
    }

    /**
     * Fatture passive anticipate dal dipendente e coperte da questa richiesta
     * di rimborso: si marcano pagate quando il rimborso viene riconciliato.
     */
    public function passiveInvoices(): HasMany
    {
        return $this->hasMany(PassiveInvoice::class);
    }

    /**
     * Costi senza fattura (km, pasti, ...) parte di questa richiesta di rimborso.
     */
    public function costi(): HasMany
    {
        return $this->hasMany(Costo::class);
    }

    /**
     * Riconciliazioni col bonifico di rimborso: il Reimbursement è il documento
     * (uscita) che il bonifico chiude.
     */
    public function reconciliations(): MorphMany
    {
        return $this->morphMany(Reconciliation::class, 'reconcilable');
    }

    /**
     * Importo da coprire con la riconciliazione bancaria (totale del rimborso).
     */
    public function total(): float
    {
        return round((float) $this->amount, 2);
    }

    /**
     * Quota già coperta da bonifici riconciliati a questo rimborso.
     */
    public function reconciledAmount(): float
    {
        return round((float) $this->reconciliations()->sum('amount'), 2);
    }
}
