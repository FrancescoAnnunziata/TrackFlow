<?php

namespace App\Models;

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

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function reconcilable(): MorphTo
    {
        return $this->morphTo();
    }
}
