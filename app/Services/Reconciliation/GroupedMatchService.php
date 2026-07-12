<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Riconcilia i pagamenti "non 1:1" fra movimenti e fatture passive:
 *  - SPEZZATI: una fattura pagata da più movimenti (es. Minerva 90,27 + 6,30);
 *  - CUMULATIVI: un movimento che paga più fatture (es. addebito Telepass che
 *    somma i pedaggi del mese).
 * Per sicurezza il nome del fornitore deve comparire nella descrizione del
 * movimento, la finestra è ±45 giorni e la combinazione deve essere UNICA.
 */
class GroupedMatchService
{
    private const WINDOW_DAYS = 45;

    private const TOLERANCE = 0.02;

    /** Oltre questo numero di candidati si rinuncia (subset-sum troppo costoso/ambiguo). */
    private const MAX_ITEMS = 12;

    public function __construct(private readonly ReconciliationService $reconciler) {}

    /**
     * Esegue entrambe le direzioni. Ritorna [spezzati, cumulativi] agganciati.
     *
     * @return array{split: int, grouped: int}
     */
    public function run(): array
    {
        return [
            'split' => $this->matchSplit(),
            'grouped' => $this->matchGrouped(),
        ];
    }

    /**
     * SPEZZATI: per ogni fattura non pagata, cerca un sottoinsieme unico di
     * uscite (stesso fornitore nella descrizione) che ne somma il totale.
     */
    public function matchSplit(): int
    {
        $linked = 0;

        foreach ($this->unpaidPassives() as $passive) {
            $target = round($passive->total() - $passive->reconciledAmount(), 2);
            if ($target <= self::TOLERANCE) {
                continue;
            }

            $movs = $this->supplierOutflows($passive);
            if ($movs->count() < 2 || $movs->count() > self::MAX_ITEMS) {
                continue;
            }

            $subset = $this->uniqueSubset(
                $movs->map(fn (BankTransaction $t): float => $t->unreconciledAmount())->all(),
                $target,
            );

            if ($subset === null || count($subset) < 2) {
                continue;
            }

            foreach ($subset as $i) {
                $tx = $movs[$i];
                $this->reconciler->attach($tx, $passive, $tx->unreconciledAmount(), Reconciliation::BY_AUTO);
            }
            $linked++;
        }

        return $linked;
    }

    /**
     * CUMULATIVI: per ogni uscita non riconciliata, cerca un sottoinsieme unico
     * di fatture non pagate (stesso fornitore nella descrizione) che ne somma
     * l'importo.
     */
    public function matchGrouped(): int
    {
        $linked = 0;

        $movs = BankTransaction::query()->unreconciled()->where('amount', '<', 0)
            ->orderBy('booked_at')->get();

        foreach ($movs as $tx) {
            $target = round($tx->unreconciledAmount(), 2);
            if ($target <= self::TOLERANCE) {
                continue;
            }

            $passives = $this->matchingPassivesFor($tx);
            if ($passives->count() < 2 || $passives->count() > self::MAX_ITEMS) {
                continue;
            }

            $subset = $this->uniqueSubset(
                $passives->map(fn (PassiveInvoice $p): float => round($p->total() - $p->reconciledAmount(), 2))->all(),
                $target,
            );

            if ($subset === null || count($subset) < 2) {
                continue;
            }

            foreach ($subset as $i) {
                $p = $passives[$i];
                $this->reconciler->attach($tx, $p, round($p->total() - $p->reconciledAmount(), 2), Reconciliation::BY_AUTO);
            }
            $linked++;
        }

        return $linked;
    }

    /**
     * @return Collection<int, PassiveInvoice>
     */
    private function unpaidPassives(): Collection
    {
        return PassiveInvoice::with('supplier')
            ->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)->whereNull('reimbursement_id')
            ->orderBy('document_date')
            ->get();
    }

    /**
     * Uscite non allocate col nome del fornitore nella descrizione, entro ±45gg.
     *
     * @return Collection<int, BankTransaction>
     */
    private function supplierOutflows(PassiveInvoice $passive): Collection
    {
        $name = (string) ($passive->supplier->name ?? '');
        $from = $passive->document_date->copy()->subDays(self::WINDOW_DAYS);
        $to = $passive->document_date->copy()->addDays(self::WINDOW_DAYS);

        return BankTransaction::query()->where('amount', '<', 0)
            ->whereBetween('booked_at', [$from, $to])->get()
            ->filter(fn (BankTransaction $t): bool => $t->unreconciledAmount() > self::TOLERANCE
                && $this->nameMatches($name, $t))
            ->values();
    }

    /**
     * Fatture non pagate col fornitore citato nel movimento, entro ±45gg.
     *
     * @return Collection<int, PassiveInvoice>
     */
    private function matchingPassivesFor(BankTransaction $tx): Collection
    {
        $from = $tx->booked_at->copy()->subDays(self::WINDOW_DAYS);
        $to = $tx->booked_at->copy()->addDays(self::WINDOW_DAYS);

        return PassiveInvoice::with('supplier')
            ->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)->whereNull('reimbursement_id')
            ->whereBetween('document_date', [$from, $to])->get()
            ->filter(fn (PassiveInvoice $p): bool => $this->nameMatches((string) ($p->supplier->name ?? ''), $tx))
            ->values();
    }

    private function nameMatches(string $name, BankTransaction $tx): bool
    {
        $haystack = Str::lower((string) ($tx->counterparty ?? '').' '.($tx->description ?? ''));
        if ($haystack === '' || trim($name) === '') {
            return false;
        }

        foreach (preg_split('/\s+/', Str::lower($name)) as $token) {
            if (mb_strlen($token) >= 4 && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trova l'UNICO sottoinsieme di $amounts che somma a $target (entro
     * tolleranza). Ritorna gli indici, o null se nessuno o più d'uno.
     *
     * @param  array<int, float>  $amounts
     * @return array<int, int>|null
     */
    public function uniqueSubset(array $amounts, float $target): ?array
    {
        $n = count($amounts);
        if ($n === 0 || $n > self::MAX_ITEMS) {
            return null;
        }

        $found = [];
        // Enumerazione dei sottoinsiemi non vuoti (n <= 12 → max 4095).
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sum = 0.0;
            $idx = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $sum += $amounts[$i];
                    $idx[] = $i;
                }
            }
            if (abs($sum - $target) <= self::TOLERANCE) {
                $found[] = $idx;
                if (count($found) > 1) {
                    return null; // ambiguo
                }
            }
        }

        return $found[0] ?? null;
    }
}
