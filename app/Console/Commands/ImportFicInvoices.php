<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Fic\FicClient;
use App\Services\Fic\FicException;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Importa lo storico delle fatture emesse da Fatture in Cloud come archivio in
 * sola lettura in TrackFlow. Idempotente: ri-eseguibile senza duplicare
 * (upsert su fic_document_id).
 */
class ImportFicInvoices extends Command
{
    protected $signature = 'fic:import-invoices
        {--pages=20 : Numero massimo di pagine da scaricare}
        {--create-clients : Crea i clienti mancanti dai dati anagrafici di FIC invece di saltare le fatture}';

    protected $description = 'Importa le fatture emesse da Fatture in Cloud (archivio storico, sola lettura).';

    public function handle(): int
    {
        $userId = User::where('role', 'admin')->value('id') ?? User::query()->value('id');

        if ($userId === null) {
            $this->error('Nessun utente su cui attribuire le fatture importate.');

            return self::FAILURE;
        }

        $fic = FicClient::fromConfig();
        $art15VatId = (int) config('services.fic.art15_vat_id');
        $createClients = (bool) $this->option('create-clients');
        $imported = 0;
        $skipped = 0;
        $maxPages = (int) $this->option('pages');

        try {
            // Fatture e note di credito: stesso endpoint, tipo diverso. Le NC
            // stornano una fattura emessa (collegamento alla fattura poi in UI).
            foreach (['invoice' => Invoice::TYPE_INVOICE, 'credit_note' => Invoice::TYPE_CREDIT_NOTE] as $ficType => $localType) {
                for ($page = 1; $page <= $maxPages; $page++) {
                    $list = $fic->listIssuedDocuments($ficType, $page);

                    if ($list === []) {
                        break;
                    }

                    foreach ($list as $summary) {
                        $detail = $fic->getInvoice((int) $summary['id']);
                        $this->importOne($detail, $userId, $art15VatId, $createClients, $localType)
                            ? $imported++
                            : $skipped++;
                    }
                }
            }
        } catch (FicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Importate/aggiornate: {$imported}. Saltate (cliente non trovato): {$skipped}.");

        if ($skipped > 0 && ! $createClients) {
            $this->line('Suggerimento: usa --create-clients per creare i clienti mancanti dai dati FIC.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function importOne(array $doc, int $userId, int $art15VatId, bool $createClients, string $type = Invoice::TYPE_INVOICE): bool
    {
        $client = $this->resolveClient((array) Arr::get($doc, 'entity', []), $createClients);

        if ($client === null) {
            return false;
        }

        // FIC riparte la numerazione ogni anno: includiamo l'anno per unicità
        // (convenzione italiana "N/anno"), evitando collisioni fra annualità.
        $base = trim((string) (Arr::get($doc, 'numeration') ?? '').' '.(Arr::get($doc, 'number') ?? ''));
        $year = Carbon::parse((string) Arr::get($doc, 'date'))->year;
        $number = ($base !== '' ? $base : (string) $doc['id']).'/'.$year;

        return DB::transaction(function () use ($doc, $client, $userId, $art15VatId, $number, $type): bool {
            // 'related_invoice_id' non è impostato qui: il collegamento della nota
            // di credito alla fattura stornata si fa a mano in UI e va preservato
            // fra un import e l'altro.
            $invoice = Invoice::updateOrCreate(
                ['fic_document_id' => (int) $doc['id']],
                [
                    'user_id' => $userId,
                    'client_id' => $client->id,
                    'number' => $number !== '' ? $number : (string) $doc['id'],
                    'type' => $type,
                    'issue_date' => Arr::get($doc, 'date'),
                    // Le fatture importate non hanno un periodo: usiamo la data doc.
                    'period_from' => Arr::get($doc, 'date'),
                    'period_to' => Arr::get($doc, 'date'),
                    'vat_rate' => $client->vat_rate ?? 22,
                    'status' => 'sent',
                    'fic_document_token' => Arr::get($doc, 'token'),
                    'fic_sent_at' => Arr::get($doc, 'date'),
                    // Le note FIC contengono il dettaglio dei rimborsi spese
                    // ("Vedi note"): le conserviamo per il recupero storico.
                    'notes' => (string) (Arr::get($doc, 'notes') ?? ''),
                    'imported' => true,
                ],
            );

            $invoice->items()->delete();

            foreach (Arr::get($doc, 'items_list', []) as $i => $item) {
                $vatId = (int) Arr::get($item, 'vat.id', 0);
                $invoice->items()->create([
                    'name' => (string) ($item['name'] ?? 'Voce'),
                    'description' => (string) ($item['description'] ?? ''),
                    'qty' => (float) ($item['qty'] ?? 1),
                    'measure' => (string) ($item['measure'] ?? ''),
                    'net_price' => (float) ($item['net_price'] ?? 0),
                    'vat_kind' => $vatId === $art15VatId ? InvoiceItem::VAT_ART15 : InvoiceItem::VAT_STANDARD,
                    'line_kind' => 'consulting',
                    'sort' => $i,
                ]);
            }

            return true;
        });
    }

    /**
     * Aggancia il cliente per P.IVA; in mancanza per nome (riempiendo la P.IVA
     * mancante); altrimenti lo crea dai dati FIC se richiesto.
     *
     * @param  array<string, mixed>  $entity
     */
    private function resolveClient(array $entity, bool $createClients): ?Client
    {
        $vat = trim((string) ($entity['vat_number'] ?? ''));
        $name = trim((string) ($entity['name'] ?? ''));

        if ($vat !== '') {
            $client = Client::where('vat_number', $vat)->first();
            if ($client !== null) {
                return $client;
            }
        }

        if ($name !== '') {
            $client = Client::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($client !== null) {
                if ($vat !== '' && blank($client->vat_number)) {
                    $client->update(['vat_number' => $vat]);
                }

                return $client;
            }
        }

        if (! $createClients || $name === '') {
            return null;
        }

        return Client::create([
            'name' => $name,
            'entity_type' => $entity['type'] ?? 'company',
            'vat_number' => $vat ?: null,
            'tax_code' => $entity['tax_code'] ?? null,
            'address_street' => $entity['address_street'] ?? null,
            'address_postal_code' => $entity['address_postal_code'] ?? null,
            'address_city' => $entity['address_city'] ?? null,
            'address_province' => $entity['address_province'] ?? null,
            'country' => $entity['country'] ?? 'Italia',
            'country_iso' => $entity['country_iso'] ?? 'IT',
            'email' => $entity['email'] ?? null,
            'certified_email' => $entity['certified_email'] ?? null,
            'ei_code' => $entity['ei_code'] ?? null,
        ]);
    }
}
