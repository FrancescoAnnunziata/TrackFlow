<?php

namespace App\Jobs;

use App\Services\Ai\ForeignInvoiceExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Estrae in background i dati dalle fatture estere in PDF (una chiamata a Claude
 * per PDF): l'operazione è lenta e con molti file supera il timeout della
 * richiesta web. Il job scrive il risultato in cache con la chiave passata dalla
 * pagina, che la interroga in polling e popola la tabella di revisione.
 */
class ExtractForeignInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Estrazione di molti PDF: ampio margine rispetto al timeout di default. */
    public int $timeout = 900;

    /** Un solo tentativo: l'estrazione non è idempotente lato costi (chiamate AI). */
    public int $tries = 1;

    /**
     * @param  array<int, string>  $paths  path dei PDF sul disco
     */
    public function __construct(
        private readonly array $paths,
        private readonly string $cacheKey,
        private readonly string $disk = 'public',
    ) {}

    public function handle(ForeignInvoiceExtractor $extractor): void
    {
        $rows = [];
        $errors = 0;

        foreach ($this->paths as $path) {
            try {
                $pdf = Storage::disk($this->disk)->exists($path)
                    ? Storage::disk($this->disk)->get($path)
                    : null;
                if (blank($pdf)) {
                    throw new \RuntimeException('PDF non leggibile (vuoto).');
                }
                $d = $extractor->extract($pdf);
                // Chiave UUID: il Repeater di Filament indicizza gli item per
                // chiave (un array numerico ne rompe il rendering).
                $rows[(string) Str::uuid()] = [
                    'attachment' => $path,
                    'supplier_name' => $d['supplier_name'],
                    'supplier_vat' => $d['supplier_vat'],
                    'number' => $d['number'],
                    'document_date' => $d['document_date'],
                    'category' => $d['category'],
                    'currency' => $d['currency'],
                    'amount_net' => $d['amount_net'],
                    'amount_vat' => $d['amount_vat'],
                    'amount_gross' => $d['amount_gross'],
                    'is_credit_note' => $d['is_credit_note'],
                ];
            } catch (Throwable) {
                $errors++;
            }
        }

        Cache::put($this->cacheKey, [
            'status' => 'done',
            'rows' => $rows,
            'errors' => $errors,
        ], now()->addHour());
    }

    public function failed(Throwable $e): void
    {
        Cache::put($this->cacheKey, [
            'status' => 'failed',
            'message' => $e->getMessage(),
        ], now()->addHour());
    }
}
