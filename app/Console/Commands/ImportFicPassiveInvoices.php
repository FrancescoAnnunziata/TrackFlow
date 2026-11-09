<?php

namespace App\Console\Commands;

use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Services\Fic\FicClient;
use App\Services\Fic\FicException;
use App\Services\Fic\IntegrationDocument;
use App\Services\Fic\IntegrationLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Importa i documenti ricevuti (fatture passive/spese) da Fatture in Cloud
 * come archivio dei costi. Idempotente: ri-eseguibile senza duplicare (upsert
 * su fic_document_id).
 */
class ImportFicPassiveInvoices extends Command
{
    protected $signature = 'fic:import-passive-invoices
        {--pages=20 : Numero massimo di pagine da scaricare per ciascun tipo}
        {--types=expense,passive_credit_note : Tipi di documento ricevuto FIC da importare (CSV). Validi: expense (fatture d\'acquisto/spese), passive_credit_note (note di credito ricevute)}
        {--create-suppliers : Crea i fornitori mancanti dai dati anagrafici di FIC invece di saltare i documenti}';

    protected $description = 'Importa le fatture passive/spese da Fatture in Cloud (archivio costi, sola lettura).';

    public function handle(): int
    {
        $fic = FicClient::fromConfig();
        $createSuppliers = (bool) $this->option('create-suppliers');
        $maxPages = (int) $this->option('pages');
        $types = array_filter(array_map('trim', explode(',', (string) $this->option('types'))));
        $imported = 0;
        $skipped = 0;
        $typeErrors = [];

        // Ogni tipo è indipendente: se FIC rifiuta un tipo (es. non valido o non
        // disponibile per l'azienda) lo segnaliamo e proseguiamo con gli altri,
        // invece di abortire tutto l'import.
        foreach ($types as $type) {
            try {
                for ($page = 1; $page <= $maxPages; $page++) {
                    $list = $fic->listReceivedDocuments($type, $page);

                    if ($list === []) {
                        break;
                    }

                    foreach ($list as $summary) {
                        $detail = $fic->getReceivedDocument((int) $summary['id'], $type);
                        $this->importOne($detail, $type, $createSuppliers)
                            ? $imported++
                            : $skipped++;
                    }
                }
            } catch (FicException $e) {
                $typeErrors[$type] = $e->getMessage();
                $this->warn("Tipo '{$type}' saltato: {$e->getMessage()}");
            }
        }

        $this->info("Importate/aggiornate: {$imported}. Saltate (fornitore non trovato): {$skipped}.");

        if ($skipped > 0 && ! $createSuppliers) {
            $this->line('Suggerimento: usa --create-suppliers per creare i fornitori mancanti dai dati FIC.');
        }

        // Falliamo solo se NESSUN tipo è stato scaricabile (tutti in errore).
        if ($typeErrors !== [] && count($typeErrors) === count($types)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function importOne(array $doc, string $type, bool $createSuppliers): bool
    {
        $integration = $this->integrationOf($doc);

        // L'integrazione si attacca al fornitore della fattura che integra (lo
        // fissa IntegrationLinker); qui serve solo un fornitore valido per
        // salvarla, anche se non ancora collegata.
        $supplier = $this->resolveSupplier((array) Arr::get($doc, 'entity', []), $createSuppliers || $integration !== null);

        if ($supplier === null) {
            return false;
        }

        // Per i documenti RICEVUTI il numero vero della fattura del fornitore è
        // in `invoice_number` (i campi numeration/number sono per i documenti
        // emessi e qui sono vuoti): usiamo quello così com'è. In sua assenza
        // ricadiamo su numeration/number e infine sull'id FIC, con l'anno
        // (convenzione italiana "N/anno") perché la numerazione riparte ogni anno.
        $invoiceNumber = trim((string) (Arr::get($doc, 'invoice_number') ?? ''));

        if ($invoiceNumber !== '') {
            $number = $invoiceNumber;
        } else {
            $base = trim((string) (Arr::get($doc, 'numeration') ?? '').' '.(Arr::get($doc, 'number') ?? ''));
            $year = Carbon::parse((string) Arr::get($doc, 'date'))->year;
            $number = ($base !== '' ? $base : (string) $doc['id']).'/'.$year;
        }

        return DB::transaction(function () use ($doc, $type, $supplier, $number, $integration): bool {
            // conIntegrazioni: il documento potrebbe essere già un'integrazione
            // (nascosta dallo scope) e va aggiornato, non ricreato.
            $passive = PassiveInvoice::conIntegrazioni()->updateOrCreate(
                ['fic_document_id' => (int) $doc['id']],
                [
                    'supplier_id' => $supplier->id,
                    'number' => $number,
                    'type' => $type,
                    'document_date' => Arr::get($doc, 'date'),
                    'due_date' => Arr::get($doc, 'due_date'),
                    'amount_net' => (float) Arr::get($doc, 'amount_net', 0),
                    'amount_vat' => (float) Arr::get($doc, 'amount_vat', 0),
                    'amount_gross' => (float) Arr::get($doc, 'amount_gross', 0),
                    'category' => Arr::get($doc, 'category'),
                    'fic_document_token' => Arr::get($doc, 'token'),
                    'imported' => true,
                    'integration_type' => $integration['type'] ?? null,
                    'integrates_number' => $integration['number'] ?? null,
                    'integrates_date' => $integration['date'] ?? null,
                ],
            );

            if ($integration !== null && $passive->integrates_id === null) {
                app(IntegrationLinker::class)->link($passive);
            }

            $passive->items()->delete();

            foreach (Arr::get($doc, 'items_list', []) as $i => $item) {
                $qty = (float) ($item['qty'] ?? 1);
                $netPrice = (float) ($item['net_price'] ?? 0);
                $vatValue = (float) Arr::get($item, 'vat.value', 0);

                $passive->items()->create([
                    'name' => (string) ($item['name'] ?? 'Voce'),
                    'description' => (string) ($item['description'] ?? ''),
                    'qty' => $qty,
                    'measure' => (string) ($item['measure'] ?? ''),
                    'net_price' => $netPrice,
                    'vat_value' => $vatValue,
                    'vat_amount' => round($qty * $netPrice * ($vatValue / 100), 2),
                    'category' => Arr::get($item, 'category'),
                    'sort' => $i,
                ]);
            }

            return true;
        });
    }

    /**
     * Se il documento è un'integrazione dell'esterometro (TD17/18/19) ne
     * restituisce tipo e fattura collegata, leggendoli dall'XML.
     *
     * L'XML si scarica solo per i documenti che possono esserlo: fornitore
     * estero (FiC scrive provincia «EE») o descrizione da integrazione. Se l'XML
     * non si legge ma la descrizione è inequivocabile, resta un'integrazione di
     * tipo non noto («INT»): meglio fuori dai costi senza collegamento che dentro
     * due volte.
     *
     * @param  array<string, mixed>  $doc
     * @return array{type: string, number: ?string, date: ?string}|null
     */
    private function integrationOf(array $doc): ?array
    {
        $text = mb_strtolower(Arr::get($doc, 'description', '').' '.Arr::get($doc, 'items_list.0.name', ''));
        // La frase intera, non «integrativ»: «contributo integrativo» è la voce
        // della cassa previdenziale sulle fatture dei professionisti italiani.
        $saysIntegration = str_contains($text, 'documento integrativo') || str_contains($text, 'autofattura');
        $foreign = Arr::get($doc, 'entity.address_province') === 'EE'
            || ! in_array(Arr::get($doc, 'entity.country'), [null, '', 'Italia'], true);

        if (! Arr::get($doc, 'e_invoice') || (! $saysIntegration && ! $foreign)) {
            return null;
        }

        $url = html_entity_decode((string) Arr::get($doc, 'attachment_url', ''));
        if ($url !== '') {
            try {
                $response = Http::timeout(20)->get($url);
                // XML letto: decide lui, in un senso o nell'altro. La descrizione
                // serve solo quando l'XML non c'è o non si legge.
                if ($response->successful() && IntegrationDocument::readable($response->body())) {
                    return IntegrationDocument::parse($response->body());
                }
            } catch (Throwable) {
                // si ricade sulla descrizione
            }
        }

        return $saysIntegration ? ['type' => 'INT', 'number' => null, 'date' => null] : null;
    }

    /**
     * Aggancia il fornitore per P.IVA; in mancanza per nome (riempiendo la
     * P.IVA mancante); altrimenti lo crea dai dati FIC se richiesto.
     *
     * @param  array<string, mixed>  $entity
     */
    private function resolveSupplier(array $entity, bool $createSuppliers): ?Supplier
    {
        $vat = trim((string) ($entity['vat_number'] ?? ''));
        $name = trim((string) ($entity['name'] ?? ''));

        if ($vat !== '') {
            $supplier = Supplier::where('vat_number', $vat)->first();
            if ($supplier !== null) {
                return $supplier;
            }
        }

        if ($name !== '') {
            $supplier = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($supplier !== null) {
                if ($vat !== '' && blank($supplier->vat_number)) {
                    $supplier->update(['vat_number' => $vat]);
                }

                return $supplier;
            }
        }

        if (! $createSuppliers || $name === '') {
            return null;
        }

        return Supplier::create([
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
            'fic_entity_id' => isset($entity['id']) ? (string) $entity['id'] : null,
        ]);
    }
}
