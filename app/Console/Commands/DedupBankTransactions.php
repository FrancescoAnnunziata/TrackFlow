<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ripulisce i movimenti bancari duplicati da re-import e normalizza il
 * dedup_hash al formato content-based stabile, così un futuro re-import non
 * duplica più. Idempotente. Eseguibile anche in produzione.
 *
 * Fase 1 — rimuove le righe SENZA reference che hanno una gemella (stesso
 * conto+data+importo+descrizione) CON reference: sono le copie generate quando
 * un import mappava l'ID transazione e l'altro no. I movimenti realmente
 * identici (nessuna gemella con reference) restano intatti.
 * Fase 2 — ricalcola dedup_hash = sha1(data|importo|datavaluta|descrizione#N)
 * per tutti, allineandoli a come li genera ora l'importer.
 */
class DedupBankTransactions extends Command
{
    protected $signature = 'finance:dedup-transactions {--dry-run : Mostra soltanto cosa farebbe}';

    protected $description = 'Rimuove i movimenti bancari duplicati da re-import e normalizza gli hash di dedup.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $duplicates = BankTransaction::query()
            ->whereNull('bank_reference')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('bank_transactions as t')
                ->whereColumn('t.bank_account_id', 'bank_transactions.bank_account_id')
                ->whereColumn('t.booked_at', 'bank_transactions.booked_at')
                ->whereColumn('t.amount', 'bank_transactions.amount')
                ->whereRaw('COALESCE(t.description,\'\') = COALESCE(bank_transactions.description,\'\')')
                ->whereColumn('t.id', '!=', 'bank_transactions.id')
                ->whereNotNull('t.bank_reference'))
            ->get();

        $this->info(($dry ? '[dry-run] ' : '').'Duplicati da re-import da rimuovere: '.$duplicates->count().'.');

        if (! $dry) {
            DB::transaction(function () use ($duplicates): void {
                foreach ($duplicates as $duplicate) {
                    $duplicate->reconciliations()->delete();
                    $duplicate->delete();
                }
            });
        }

        // Fase 2: normalizza gli hash (anche in dry-run è utile contarli).
        $normalized = 0;
        foreach (BankAccount::pluck('id') as $accountId) {
            $seen = [];
            BankTransaction::where('bank_account_id', $accountId)
                ->orderBy('booked_at')->orderBy('id')
                ->get()
                ->each(function (BankTransaction $t) use (&$seen, &$normalized, $dry): void {
                    $base = $this->baseFor($t);
                    $seen[$base] = ($seen[$base] ?? 0) + 1;
                    $hash = sha1($base.'#'.$seen[$base]);

                    if ($t->dedup_hash !== $hash) {
                        $normalized++;
                        if (! $dry) {
                            $t->forceFill(['dedup_hash' => $hash])->saveQuietly();
                        }
                    }
                });
        }

        $this->info(($dry ? '[dry-run] ' : '').'Hash normalizzati su '.$normalized.' movimenti.');

        return self::SUCCESS;
    }

    /**
     * Stessa formula usata dall'importer (BankCsvImporter), così gli hash
     * combaciano fra dati esistenti e futuri import.
     */
    private function baseFor(BankTransaction $t): string
    {
        return $t->booked_at->format('Y-m-d').'|'
            .number_format((float) $t->amount, 2, '.', '').'|'
            .(optional($t->value_date)->format('Y-m-d') ?? '').'|'
            .mb_strtolower((string) $t->description);
    }
}
