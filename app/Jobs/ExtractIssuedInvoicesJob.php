<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Ai\IssuedInvoiceExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Estrae in background i dati dalle fatture emesse in PDF (una chiamata a Claude
 * per PDF). Come per le fatture estere, l'operazione è troppo lenta per la
 * richiesta web: il risultato finisce in cache e la pagina lo interroga in
 * polling per popolare la tabella di revisione.
 */
class ExtractIssuedInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /** Un solo tentativo: ogni retry costerebbe un'altra chiamata al modello. */
    public int $tries = 1;

    /**
     * @param  array<int, string>  $paths  path dei PDF sul disco
     */
    public function __construct(
        private readonly array $paths,
        private readonly string $cacheKey,
        private readonly string $disk = 'public',
    ) {}

    public function handle(IssuedInvoiceExtractor $extractor): void
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
                $date = $this->safeDate($d['document_date']);
                $client = self::matchClient($d['client_name'], $d['client_vat'], $d['client_tax_code']);
                $type = $d['is_credit_note'] ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE;

                // Chiave UUID: il Repeater di Filament indicizza gli item per
                // chiave (un array numerico ne rompe il rendering).
                $rows[(string) Str::uuid()] = [
                    'attachment' => $path,
                    'source_name' => basename($path),
                    'extracted_client' => $d['client_name'],
                    'client_id' => $client?->id,
                    // Bozza generata in TrackFlow che questo PDF va a rimpiazzare:
                    // proposta solo quando il totale coincide (vedi matchDraft).
                    'replaces_invoice_id' => $client === null
                        ? null
                        : self::matchDraft($client->id, $type, $d['total'])?->id,
                    'number' => $d['number'],
                    'issue_date' => $date?->toDateString(),
                    // Nessun periodo sulle fatture Fiscozen: si assume il mese
                    // della data documento, correggibile a mano.
                    'period_from' => $date?->copy()->startOfMonth()->toDateString(),
                    'period_to' => $date?->copy()->endOfMonth()->toDateString(),
                    'vat_rate' => $d['vat_rate'],
                    'total' => $d['total'],
                    'is_credit_note' => $d['is_credit_note'],
                    'lines' => collect($d['lines'])
                        ->mapWithKeys(fn (array $line): array => [(string) Str::uuid() => $line])
                        ->all(),
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

    /**
     * Cliente corrispondente, se individuabile: prima i codici (P.IVA, C.F.),
     * poi il nome. Il nome sul PDF è la ragione sociale completa ("QUISTO SRL")
     * mentre in anagrafica sta spesso in forma breve ("Quisto"), quindi il
     * confronto va tentato nei due versi.
     */
    public static function matchClient(string $name, ?string $vat, ?string $taxCode): ?Client
    {
        foreach ([$vat, $taxCode] as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $client = Client::where('vat_number', $code)->orWhere('tax_code', $code)->first();
            if ($client !== null) {
                return $client;
            }
        }

        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $exact = Client::whereRaw('LOWER(name) = ?', [$name])->first();
        if ($exact !== null) {
            return $exact;
        }

        // Nome anagrafica contenuto in quello del PDF ("Quisto" ⊂ "QUISTO SRL")
        // o viceversa. Il più lungo vince: evita che "G8" peschi prima di "G8Labs".
        return Client::all()
            ->filter(function (Client $client) use ($name): bool {
                $candidate = mb_strtolower(trim((string) $client->name));

                return $candidate !== '' && (str_contains($name, $candidate) || str_contains($candidate, $name));
            })
            ->sortByDesc(fn (Client $client): int => mb_strlen((string) $client->name))
            ->first();
    }

    /**
     * Bozze generate in TrackFlow per questo cliente, candidate a essere
     * rimpiazzate dal PDF: la fattura l'hai preparata qui e poi emessa davvero
     * sul gestionale esterno, che le ha dato il numero.
     *
     * @return Collection<int, Invoice>
     */
    public static function draftCandidates(int $clientId, string $type): Collection
    {
        return Invoice::with('items')
            ->where('client_id', $clientId)
            ->where('type', $type)
            ->where('status', 'draft')
            // Solo quelle nate qui: le importate rappresentano già un documento reale.
            ->where('imported', false)
            ->orderByDesc('period_to')
            ->get();
    }

    /**
     * La bozza da proporre come rimpiazzo, se c'è. Si propone SOLO quando il
     * totale coincide con quello del PDF: è l'unico segnale forte che si tratta
     * della stessa fattura. Senza, si lascia decidere all'utente nella revisione
     * (rimpiazzare la bozza sbagliata significherebbe perderne le righe).
     */
    public static function matchDraft(int $clientId, string $type, float $total): ?Invoice
    {
        if ($total <= 0) {
            return null;
        }

        return self::draftCandidates($clientId, $type)
            ->first(fn (Invoice $invoice): bool => abs($invoice->total() - $total) <= 0.02);
    }

    private function safeDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function failed(Throwable $e): void
    {
        Cache::put($this->cacheKey, [
            'status' => 'failed',
            'message' => $e->getMessage(),
        ], now()->addHour());
    }
}
