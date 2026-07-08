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
        {--apply : Crea davvero le spese (senza questo flag è solo un\'anteprima)}';

    protected $description = 'Recupera le spese riaddebitate dalle fatture attive storiche e le crea come Expense.';

    public function handle(InvoiceExpenseExtractor $extractor): int
    {
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
}
