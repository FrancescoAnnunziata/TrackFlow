<?php

namespace App\Models;

use App\Exceptions\EmittentiDiversiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Collega un movimento bancario a un documento (fattura attiva/passiva o
 * costo). `amount` è la quota di movimento allocata al documento.
 */
class Reconciliation extends Model
{
    public const BY_MANUAL = 'manual';

    public const BY_AUTO = 'auto';

    protected $fillable = [
        'bank_transaction_id',
        'reconcilable_type',
        'reconcilable_id',
        'amount',
        'matched_by',
        'confidence',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'confidence' => 'integer',
    ];

    protected static function booted(): void
    {
        // Un movimento si riconcilia solo con documenti dello stesso emittente:
        // vale per l'interfaccia, la riconciliazione automatica e l'assistente.
        static::creating(function (Reconciliation $reconciliation): void {
            $transaction = $reconciliation->bankTransaction;
            $document = $reconciliation->reconcilable;
            if ($transaction === null || $document === null) {
                return;
            }

            if (Emittente::idDi($transaction) !== Emittente::idDi($document)) {
                throw new EmittentiDiversiException(sprintf(
                    'Il movimento è sul conto «%s» di %s, il documento è di %s: non si possono riconciliare.',
                    $transaction->bankAccount?->name ?? '—',
                    Emittente::find(Emittente::idDi($transaction))?->nome ?? '—',
                    Emittente::find(Emittente::idDi($document))?->nome ?? '—',
                ));
            }
        });
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function reconcilable(): MorphTo
    {
        return $this->morphTo();
    }
}
