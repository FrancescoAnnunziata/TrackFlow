<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Fic\FicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Recupera da Fatture in Cloud le note delle fatture emesse già importate e le
 * salva su invoices.notes. Serve per lo storico: le note FIC contengono il
 * dettaglio dei rimborsi spese ("Vedi note") che l'import iniziale scartava.
 *
 * Va eseguito dove il token FIC è utilizzabile (produzione): in locale, su una
 * copia del DB con APP_KEY diversa, i token cifrati non sono decifrabili.
 * Idempotente e in sola lettura su FIC.
 */
class BackfillFicInvoiceNotes extends Command
{
    protected $signature = 'fic:backfill-invoice-notes
        {--only-empty : Aggiorna solo le fatture che non hanno già delle note}';

    protected $description = 'Scarica da Fatture in Cloud le note delle fatture emesse importate e le salva in locale.';

    public function handle(): int
    {
        $fic = FicClient::fromConfig();

        // Preflight: verifica che il token FIC sia utilizzabile qui. In locale,
        // su un dump di produzione con APP_KEY diversa, la decifratura fallisce
        // ("MAC is invalid"): meglio dirlo subito che lanciare N chiamate.
        try {
            $fic->accessToken();
        } catch (\Throwable $e) {
            $this->error('Token Fatture in Cloud non utilizzabile qui: '.$e->getMessage());
            $this->line('Esegui questo comando in produzione, dove il token è decifrabile.');

            return self::FAILURE;
        }

        $query = Invoice::whereNotNull('fic_document_id');
        if ($this->option('only-empty')) {
            $query->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', ''));
        }

        $invoices = $query->orderBy('issue_date')->get();

        if ($invoices->isEmpty()) {
            $this->info('Nessuna fattura importata da aggiornare.');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        $this->withProgressBar($invoices, function (Invoice $invoice) use ($fic, &$updated, &$failed): void {
            try {
                $doc = $fic->getInvoice((int) $invoice->fic_document_id);
                $notes = (string) (Arr::get($doc, 'notes') ?? '');

                if ($notes !== (string) $invoice->notes) {
                    $invoice->forceFill(['notes' => $notes])->saveQuietly();
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Fattura {$invoice->number} (doc {$invoice->fic_document_id}): {$e->getMessage()}");
            }
        });

        $this->newLine(2);
        $this->info("Note aggiornate: {$updated}/{$invoices->count()}.".($failed > 0 ? " Errori: {$failed}." : ''));

        return self::SUCCESS;
    }
}
