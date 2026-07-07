<?php

namespace App\Models;

use App\Observers\ExpenseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy(ExpenseObserver::class)]
class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'amount',
        'paid_with_personal_card',
        'attachaments',
        'client_id',
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
}
