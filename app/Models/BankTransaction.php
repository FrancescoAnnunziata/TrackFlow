<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'transfer_group_id',
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
     * Tutti i movimenti della stessa partita di giro (giroconto), questo incluso.
     */
    public function transferGroup(): HasMany
    {
        return $this->hasMany(self::class, 'transfer_group_id', 'transfer_group_id');
    }

    /**
     * Gli altri movimenti della stessa partita di giro (questo escluso). Per un
     * giroconto 1↔1 è un solo gemello; per una partita di giro uno-a-molti sono N.
     *
     * @return Collection<int, self>
     */
    public function transferCounterparts(): Collection
    {
        if ($this->transfer_group_id === null) {
            return new Collection;
        }

        return self::query()
            ->with('bankAccount')
            ->where('transfer_group_id', $this->transfer_group_id)
            ->where('id', '!=', $this->id)
            ->get();
    }

    /**
     * True se il movimento fa parte di una partita di giro / giroconto (marcato).
     */
    public function isTransfer(): bool
    {
        return $this->transfer_group_id !== null;
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

    /**
     * Elenco leggibile dei documenti a cui il movimento è riconciliato, con la
     * quota allocata e il modello sorgente (per costruire i link in Filament).
     *
     * @return array<int, array{model: ?Model, label: string, amount: float, matchedBy: ?string}>
     */
    public function reconciliationDetails(): array
    {
        return $this->reconciliations()
            ->with('reconcilable')
            ->get()
            ->map(fn (Reconciliation $r): array => [
                'model' => $r->reconcilable,
                'label' => self::describeReconcilable($r->reconcilable),
                'amount' => (float) $r->amount,
                'matchedBy' => $r->matched_by,
            ])
            ->all();
    }

    private static function describeReconcilable(?Model $doc): string
    {
        return match (true) {
            $doc instanceof Invoice => sprintf(
                '%s %s — %s',
                $doc->isCreditNote() ? 'Nota di credito' : 'Fattura',
                $doc->number,
                $doc->client->name ?? '—',
            ),
            $doc instanceof PassiveInvoice => sprintf('Fattura passiva %s — %s', $doc->number, $doc->supplier->name ?? '—'),
            $doc instanceof Costo => sprintf('Costo — %s', $doc->description),
            $doc instanceof Reimbursement => sprintf('Rimborso spese %s — %s', $doc->date?->format('d/m/Y') ?? '', $doc->notes ?? ''),
            $doc instanceof Expense => sprintf('Spesa — %s', $doc->conto ?? $doc->notes ?? ''),
            $doc === null => 'Documento non disponibile',
            default => class_basename($doc),
        };
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
