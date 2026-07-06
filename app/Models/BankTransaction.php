<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Movimento bancario importato da CSV. amount è con segno: positivo=entrata,
 * negativo=uscita. reconciled è denormalizzato per filtri/report veloci.
 */
class BankTransaction extends Model
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'bank_account_id',
        'booked_at',
        'value_date',
        'amount',
        'direction',
        'description',
        'counterparty',
        'bank_reference',
        'dedup_hash',
        'reconciled',
        'raw',
        'notes',
    ];

    protected $casts = [
        'booked_at' => 'date',
        'value_date' => 'date',
        'amount' => 'decimal:2',
        'reconciled' => 'boolean',
        'raw' => 'array',
    ];

    protected static function booted(): void
    {
        // La direzione è sempre derivata dal segno dell'importo, così resta
        // coerente sia dall'import CSV che dalle modifiche manuali.
        static::saving(function (self $transaction): void {
            $transaction->direction = (float) $transaction->amount >= 0
                ? self::DIRECTION_IN
                : self::DIRECTION_OUT;
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    /**
     * Quota del movimento già allocata a dei documenti.
     */
    public function reconciledAmount(): float
    {
        return round((float) $this->reconciliations()->sum('amount'), 2);
    }

    /**
     * Quota del movimento ancora da riconciliare (in valore assoluto).
     */
    public function unreconciledAmount(): float
    {
        return round(abs((float) $this->amount) - $this->reconciledAmount(), 2);
    }

    public function scopeUnreconciled(Builder $query): Builder
    {
        return $query->where('reconciled', false);
    }

    public function scopeEntrate(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    public function scopeUscite(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }
}
