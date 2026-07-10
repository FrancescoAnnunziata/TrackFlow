<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Riconcilia le fatture passive ai pagamenti che le CITANO esplicitamente nella
 * descrizione. Molti bonifici riportano numero e data della fattura (es.
 * "Numero Fattura: FPR 3/26 26/05/2026"): la data citata è un segnale molto più
 * affidabile del solo importo. Lock triplo per sicurezza — stesso fornitore +
 * data citata = data documento + importo esatto — così non si creano falsi
 * abbinamenti.
 *
 * Corregge anche gli abbinamenti sbagliati dell'auto-match: se una fattura è
 * riconciliata AUTOMATICAMENTE a un movimento che non la cita, ma esiste il
 * movimento che la cita, sposta la riconciliazione su quello giusto. Non tocca
 * mai le riconciliazioni manuali. Idempotente; eseguibile in produzione.
 */
class MatchInvoiceReferences extends Command
{
    protected $signature = 'finance:match-references {--dry-run : Mostra soltanto cosa farebbe}';

    protected $description = 'Riconcilia (e corregge) le fatture passive citate per numero/data nella descrizione del pagamento.';

    public function __construct(private readonly ReconciliationService $reconciler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $linked = 0;
        $corrected = 0;

        $invoices = PassiveInvoice::with(['supplier', 'reconciliations'])
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)
            ->whereNotNull('document_date')
            ->get();

        foreach ($invoices as $invoice) {
            $match = $this->citingTransaction($invoice);
            if ($match === null) {
                continue;
            }

            // Già riconciliata proprio a questo movimento: nulla da fare.
            if ($invoice->reconciliations->contains(fn (Reconciliation $r): bool => $r->bank_transaction_id === $match->id)) {
                continue;
            }

            // Se ha una riconciliazione MANUALE non la tocchiamo.
            if ($invoice->reconciliations->contains(fn (Reconciliation $r): bool => $r->matched_by === Reconciliation::BY_MANUAL)) {
                continue;
            }

            $isCorrection = $invoice->reconciliations->isNotEmpty();

            $this->line(sprintf(
                '  %s fattura #%d %s (%s) → tx#%d [%s]',
                $isCorrection ? 'CORREGGE' : 'collega ',
                $invoice->id, $invoice->number, $invoice->supplier->name ?? '—',
                $match->id, optional($match->booked_at)->format('d/m/Y'),
            ));

            if ($dry) {
                $isCorrection ? $corrected++ : $linked++;

                continue;
            }

            // Correzione: stacca le (auto) riconciliazioni sbagliate.
            foreach ($invoice->reconciliations as $rec) {
                $this->reconciler->detach($rec);
            }

            $this->reconciler->attach($match, $invoice, round($invoice->total(), 2), Reconciliation::BY_MANUAL);
            $isCorrection ? $corrected++ : $linked++;
        }

        $this->info(($dry ? '[dry-run] ' : '')."Collegate: {$linked} · Corrette: {$corrected}");

        return self::SUCCESS;
    }

    /**
     * L'unico movimento (uscita) che cita la fattura: stesso fornitore, la data
     * documento compare nella descrizione, importo esatto. null se assente o
     * ambiguo.
     */
    private function citingTransaction(PassiveInvoice $invoice): ?BankTransaction
    {
        $name = (string) ($invoice->supplier->name ?? '');
        if (trim($name) === '') {
            return null;
        }

        $gross = round((float) $invoice->amount_gross, 2);
        $docDate = $invoice->document_date;

        $candidates = BankTransaction::query()
            ->where('amount', '<', 0)
            ->whereRaw('ABS(ABS(amount) - ?) < 0.01', [$gross])
            ->get()
            ->filter(function (BankTransaction $t) use ($name, $docDate): bool {
                $hay = Str::lower(($t->counterparty ?? '').' '.($t->description ?? ''));

                return $this->nameMatches($name, $hay) && $this->citesDate($t->description ?? '', $docDate);
            })
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function nameMatches(string $name, string $haystack): bool
    {
        foreach (preg_split('/\s+/', Str::lower($name)) as $token) {
            if (mb_strlen($token) >= 4 && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True se la descrizione contiene la data documento (formato gg/mm/aaaa o
     * gg-mm-aaaa).
     */
    private function citesDate(string $description, Carbon $docDate): bool
    {
        if (! preg_match_all('/\b(\d{2})[\/\-](\d{2})[\/\-](\d{4})\b/', $description, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $m) {
            try {
                $d = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1]);
                if ($d->isSameDay($docDate)) {
                    return true;
                }
            } catch (\Throwable) {
                // data non valida: ignora
            }
        }

        return false;
    }
}
