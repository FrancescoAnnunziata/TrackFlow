<?php

namespace App\Models;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
