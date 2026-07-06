<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conto bancario. Il saldo corrente è il saldo iniziale più la somma dei
 * movimenti (con segno).
 */
class BankAccount extends Model
{
    protected $fillable = [
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

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function currentBalance(): float
    {
        return round((float) $this->opening_balance + (float) $this->transactions()->sum('amount'), 2);
    }
}
