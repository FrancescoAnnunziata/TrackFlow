<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceExpenseExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ricrea come Expense le spese storiche riaddebitate ai clienti nelle fatture
 * attive (solo clienti Fatture in Cloud), collegando ciascuna alla fattura di
 * riaddebito. Di default è un'anteprima (dry-run): scrive solo con --apply.
 *
 * Va lanciato dopo `fic:backfill-invoice-notes` (in produzione) e il re-sync in
 * locale, così anche i "Vedi note" hanno il dettaglio da scomporre.
 */
class ExtractExpensesFromInvoices extends Command
{
    protected $signature = 'expenses:from-invoices
        {--apply : Crea davvero le spese (senza questo flag è solo un\'anteprima)}
        {--rollback : Elimina le spese create da questo comando (identificate dalla nota "Riaddebito da fattura…")}';

    protected $description = 'Recupera le spese riaddebitate dalle fatture attive storiche e le crea come Expense.';

    /** Prefisso della nota con cui il comando marca le spese che crea. */
    private const NOTE_PREFIX = 'Riaddebito da fattura';

    public function handle(InvoiceExpenseExtractor $extractor): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $userId = User::where('role', 'admin')->value('id') ?? User::query()->value('id');

        if ($userId === null) {
            $this->error('Nessun utente su cui attribuire le spese.');

            return self::FAILURE;
        }

        $proposals = $extractor->proposals();

        if ($proposals->isEmpty()) {
            $this->info('Nessuna spesa da recuperare (già presenti o nessuna fattura idonea).');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $totalExpenses = 0;
        $totalAmount = 0.0;

        foreach ($proposals as $proposal) {
            /** @var Invoice $invoice */
            $invoice = $proposal['invoice'];
            $this->line("<info>Fattura {$invoice->number}</info> — {$invoice->client->name}");

            foreach ($proposal['expenses'] as $e) {
                $this->line(sprintf(
                    '   %s  € %8s  %-14s %s',
                    $e['itemized'] ? '·' : '=',
                    number_format($e['amount'], 2, ',', '.'),
                    $e['conto'] ?? '—',
                    $e['notes'],
                ));
                $totalExpenses++;
                $totalAmount += $e['amount'];
            }

            if ($apply) {
                DB::transaction(function () use ($invoice, $proposal, $userId): void {
                    foreach ($proposal['expenses'] as $e) {
                        $expense = Expense::create([
                            'user_id' => $userId,
                            'client_id' => $invoice->client_id,
                            'date' => $e['date'],
                            'amount' => $e['amount'],
                            'conto' => $e['conto'],
                            'notes' => $e['notes'],
                        ]);
                        $expense->invoices()->attach($invoice->id);
                    }
                });
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d spese da %d fatture, totale € %s.',
            $apply ? 'Create' : 'Anteprima:',
            $totalExpenses,
            $proposals->count(),
            number_format($totalAmount, 2, ',', '.'),
        ));

        if (! $apply) {
            $this->line('Legenda: "·" voce scomposta, "=" importo unico di riga. Rilancia con --apply per creare.');
        }

        return self::SUCCESS;
    }

    /**
     * Elimina le spese create da questo comando (marcate dalla nota
     * "Riaddebito da fattura…"), scollegandole prima dalle fatture. Utile quando
     * i costi sono poi coperti dalle fatture passive importate.
     */
    private function rollback(): int
    {
        $expenses = Expense::where('notes', 'like', self::NOTE_PREFIX.'%')->get();

        if ($expenses->isEmpty()) {
            $this->info('Nessuna spesa da rimuovere.');

            return self::SUCCESS;
        }

        $count = $expenses->count();
        $total = round($expenses->sum('amount'), 2);

        DB::transaction(function () use ($expenses): void {
            foreach ($expenses as $expense) {
                $expense->invoices()->detach();
                $expense->delete();
            }
        });

        $this->info(sprintf('Rimosse %d spese storiche (totale € %s).', $count, number_format($total, 2, ',', '.')));

        return self::SUCCESS;
    }
}
