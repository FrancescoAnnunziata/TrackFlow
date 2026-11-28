<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conto bancario. Il saldo corrente è il saldo iniziale più la somma dei
 * movimenti (con segno).
 */
class BankAccount extends Model
{
    protected $fillable = [
        'emittente_id',
        'name',
        'bank_key',
        'iban',
        'currency',
        'opening_balance',
        'opening_balance_date',
        'active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Senza indicazione il conto è della società.
        static::creating(function (BankAccount $account): void {
            $account->emittente_id ??= Emittente::principale()->id;
        });
    }

    /**
     * Titolare del conto: i movimenti si riconciliano solo con documenti dello
     * stesso emittente.
     */
    public function emittente(): BelongsTo
    {
        return $this->belongsTo(Emittente::class);
    }

    /**
     * Solo i conti della società: cassa e prima nota di G8 Labs.
     */
    public function scopeDellaSocieta(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('emittente_id'), Emittente::principale()->id);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function currentBalance(): float
    {
        return round((float) $this->opening_balance + (float) $this->transactions()->sum('amount'), 2);
    }
}
