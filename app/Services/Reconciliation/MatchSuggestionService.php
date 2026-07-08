<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Propone i documenti candidati alla riconciliazione di un movimento bancario,
 * ordinati per confidenza. Le entrate puntano alle fatture attive non pagate,
 * le uscite alle fatture passive/costi non pagati; il segno deve combaciare.
 */
class MatchSuggestionService
{
    /** Finestra temporale entro cui cercare i candidati. */
    private const WINDOW_DAYS = 120;

    /**
     * @return Collection<int, array{model: Model, label: string, amount: float, confidence: int}>
     */
    public function suggestions(BankTransaction $transaction, int $limit = 8): Collection
    {
        $amount = abs((float) $transaction->amount);
        $candidates = $transaction->amount >= 0
            ? $this->activeInvoiceCandidates($transaction)
            : $this->costCandidates($transaction);

        return $candidates
            ->map(fn (array $c): array => [
                'model' => $c['model'],
                'label' => $c['label'],
                'amount' => $c['amount'],
                'confidence' => $this->score($amount, $c['amount'], $transaction, $c['date'], $c['name']),
            ])
            ->filter(fn (array $c): bool => $c['confidence'] > 0)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, array{model: Model, label: string, amount: float, date: ?Carbon, name: string}>
     */
    private function activeInvoiceCandidates(BankTransaction $transaction): Collection
    {
        return Invoice::with('client')
            ->where('status', '!=', 'paid')
            ->whereBetween('issue_date', [
                $transaction->booked_at->copy()->subDays(self::WINDOW_DAYS),
                $transaction->booked_at->copy()->addDays(self::WINDOW_DAYS),
            ])
            ->limit(200)
            ->get()
            ->map(fn (Invoice $i): array => [
                'model' => $i,
                'label' => sprintf('Fattura %s — %s', $i->number, $i->client->name ?? '—'),
                'amount' => $i->total(),
                'date' => $i->issue_date,
                'name' => (string) ($i->client->name ?? ''),
            ]);
    }

    /**
     * @return Collection<int, array{model: Model, label: string, amount: float, date: ?Carbon, name: string}>
     */
    private function costCandidates(BankTransaction $transaction): Collection
    {
        $from = $transaction->booked_at->copy()->subDays(self::WINDOW_DAYS);
        $to = $transaction->booked_at->copy()->addDays(self::WINDOW_DAYS);

        $passive = PassiveInvoice::with('supplier')
            ->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)
            ->whereBetween('document_date', [$from, $to])
            ->limit(200)
            ->get()
            ->map(fn (PassiveInvoice $p): array => [
                'model' => $p,
                'label' => sprintf('Fattura passiva %s — %s', $p->number, $p->supplier->name ?? '—'),
                'amount' => $p->total(),
                'date' => $p->document_date,
                'name' => (string) ($p->supplier->name ?? ''),
            ]);

        $costi = Costo::with('supplier')
            ->whereBetween('date', [$from, $to])
            ->limit(200)
            ->get()
            ->map(fn (Costo $c): array => [
                'model' => $c,
                'label' => sprintf('Costo — %s', $c->description),
                'amount' => $c->total(),
                'date' => $c->date,
                'name' => (string) ($c->supplier->name ?? $c->description),
            ]);

        // Le spese (scontrini) sono documenti di costo riconciliabili: entrano
        // fra i candidati solo se non ancora coperte da riconciliazioni. Le spese
        // già collegate a una fattura passiva sono escluse: rappresentano lo
        // stesso costo, che si riconcilia sulla fattura passiva (evita il doppio
        // conteggio); la catena verso il riaddebito resta comunque tracciata dal
        // link spesa→passiva.
        $expenses = Expense::with(['supplier', 'client'])
            ->withSum('reconciliations', 'amount')
            ->whereNull('passive_invoice_id')
            ->whereBetween('date', [$from, $to])
            ->limit(200)
            ->get()
            ->filter(fn (Expense $e): bool => (float) ($e->reconciliations_sum_amount ?? 0) + 0.01 < $e->total())
            ->map(fn (Expense $e): array => [
                'model' => $e,
                'label' => sprintf('Spesa — %s', $e->supplier->name ?? $e->notes ?? (optional($e->date)->format('d/m/Y') ?? '')),
                'amount' => $e->total(),
                'date' => $e->date,
                'name' => (string) ($e->supplier->name ?? $e->client->name ?? ''),
            ]);

        return $passive->concat($costi)->concat($expenses);
    }

    /**
     * Punteggio 0-100: importo (max 60) + prossimità data (max 25) + nome
     * controparte (max 15).
     */
    private function score(float $txAmount, float $docAmount, BankTransaction $transaction, ?Carbon $docDate, string $name): int
    {
        $docAmount = abs($docAmount);
        if ($docAmount <= 0) {
            return 0;
        }

        $score = 0;

        $diff = abs($txAmount - $docAmount);
        if ($diff <= 0.01) {
            $score += 60;
        } elseif ($docAmount > 0 && $diff / $docAmount <= 0.01) {
            $score += 40;
        } else {
            // Importo troppo diverso: non è un candidato.
            return 0;
        }

        if ($docDate !== null) {
            $days = abs($docDate->diffInDays($transaction->booked_at));
            if ($days <= 45) {
                $score += (int) round(25 * (1 - $days / 45));
            }
        }

        $haystack = mb_strtolower(trim(($transaction->counterparty ?? '').' '.($transaction->description ?? '')));
        if ($haystack !== '' && $name !== '') {
            foreach (preg_split('/\s+/', mb_strtolower($name)) as $token) {
                if (mb_strlen($token) >= 4 && str_contains($haystack, $token)) {
                    $score += 15;
                    break;
                }
            }
        }

        return min(100, $score);
    }
}
