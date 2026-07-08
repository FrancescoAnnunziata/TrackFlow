<?php

namespace App\Services\Billing;

use App\Models\Expense;
use App\Models\PassiveInvoice;
use Illuminate\Support\Collection;

/**
 * Abbina le spese (scontrini) alle fatture passive importate da Fatture in
 * Cloud: stessa uscita vista due volte (la spesa col cliente/riaddebito e lo
 * scontrino, la fattura passiva col conto e lo split IVA). Al collegamento la
 * spesa eredita fornitore e conto dalla fattura passiva.
 */
class ExpensePassiveMatcher
{
    /** Finestra (giorni) entro cui cercare candidati per la stessa spesa. */
    private const WINDOW_DAYS = 15;

    /**
     * Finestra (± giorni) entro cui l'auto-collegamento accetta uno scarto fra
     * la data della spesa e quella della fattura passiva: la data del documento
     * fiscale spesso differisce di qualche giorno dall'acquisto.
     */
    private const LINK_WINDOW_DAYS = 5;

    /**
     * Collega una spesa a una fattura passiva ed eredita fornitore e conto (la
     * categoria di Fatture in Cloud), senza sovrascrivere valori già presenti
     * sulla spesa.
     */
    public function link(Expense $expense, PassiveInvoice $passive): void
    {
        $expense->passive_invoice_id = $passive->id;

        if (blank($expense->supplier_id)) {
            $expense->supplier_id = $passive->supplier_id;
        }

        if (blank($expense->conto) && filled($passive->category)) {
            $expense->conto = $passive->category;
        }

        $expense->save();
    }

    /**
     * Collega automaticamente le spese non ancora abbinate alla fattura passiva
     * con lo stesso importo lordo, a due livelli: prima la data esatta, poi (solo
     * se non c'è un match esatto) entro ±5 giorni. In entrambi i casi collega solo
     * se il candidato è unico, così gli acquisti ricorrenti a stesso importo (che
     * la finestra renderebbe ambigui) restano al collegamento manuale. Ritorna il
     * numero di spese collegate.
     */
    public function autoLinkExact(): int
    {
        $used = PassiveInvoice::whereIn(
            'id',
            Expense::whereNotNull('passive_invoice_id')->pluck('passive_invoice_id')
        )->pluck('id')->all();

        $linked = 0;

        foreach (Expense::whereNull('passive_invoice_id')->orderBy('date')->get() as $expense) {
            // Prima la data esatta; se non risolve, si allarga a ±5 giorni.
            $passive = $this->uniquePassiveFor($expense, $used, 0)
                ?? $this->uniquePassiveFor($expense, $used, self::LINK_WINDOW_DAYS);

            if ($passive === null) {
                continue;
            }

            $this->link($expense, $passive);
            $used[] = $passive->id;
            $linked++;
        }

        return $linked;
    }

    /**
     * L'unica fattura passiva con lo stesso importo della spesa entro ±$days
     * giorni (e non già usata). null se non ce n'è, o se ce n'è più d'una.
     *
     * @param  array<int, int>  $used
     */
    private function uniquePassiveFor(Expense $expense, array $used, int $days): ?PassiveInvoice
    {
        $from = $expense->date->copy()->subDays($days);
        $to = $expense->date->copy()->addDays($days);

        $matches = PassiveInvoice::whereBetween('document_date', [$from, $to])
            ->where('amount_gross', $expense->amount)
            ->whereNotIn('id', $used)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Candidati (fatture passive non collegate ad altre spese) per una spesa,
     * ordinati per confidenza. Punto d'aggancio per un eventuale fallback AI sui
     * casi che il match deterministico non risolve.
     *
     * @return Collection<int, array{model: PassiveInvoice, confidence: int, label: string}>
     */
    public function suggestions(Expense $expense, int $limit = 8): Collection
    {
        $used = Expense::whereNotNull('passive_invoice_id')
            ->where('id', '!=', $expense->id)
            ->pluck('passive_invoice_id');

        $from = $expense->date->copy()->subDays(self::WINDOW_DAYS);
        $to = $expense->date->copy()->addDays(self::WINDOW_DAYS);

        return PassiveInvoice::with('supplier')
            ->whereNotIn('id', $used)
            ->whereBetween('document_date', [$from, $to])
            ->get()
            ->map(fn (PassiveInvoice $p): array => [
                'model' => $p,
                'confidence' => $this->score($expense, $p),
                'label' => sprintf('%s %s — %s (€%s)',
                    optional($p->document_date)->format('d/m/Y') ?? '',
                    $p->number ?: '',
                    $p->supplier->name ?? '—',
                    number_format((float) $p->amount_gross, 2, ',', '.'),
                ),
            ])
            ->filter(fn (array $c): bool => $c['confidence'] > 0)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();
    }

    /**
     * Punteggio 0-100: importo (max 70) + prossimità data (max 30).
     */
    private function score(Expense $expense, PassiveInvoice $passive): int
    {
        $expenseAmount = round((float) $expense->amount, 2);
        $passiveAmount = round((float) $passive->amount_gross, 2);

        if ($passiveAmount <= 0) {
            return 0;
        }

        $score = 0;

        $diff = abs($expenseAmount - $passiveAmount);
        if ($diff <= 0.01) {
            $score += 70;
        } elseif ($passiveAmount > 0 && $diff / $passiveAmount <= 0.02) {
            $score += 40;
        } else {
            return 0;
        }

        $days = $passive->document_date ? abs($passive->document_date->diffInDays($expense->date)) : self::WINDOW_DAYS;
        $score += (int) max(0, round(30 * (1 - min($days, self::WINDOW_DAYS) / self::WINDOW_DAYS)));

        return $score;
    }
}
