<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\VatNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Trasforma un abbonamento incassato altrove in una fattura di TrackFlow.
 *
 * Tre principi, in ordine di importanza:
 *
 * 1. **Un pagamento, una fattura.** La coppia (source, source_id) ha un unique
 *    a database: qualunque cosa facciano i retry del chiamante, la seconda
 *    chiamata ritrova la prima fattura invece di crearne un'altra.
 * 2. **Gli importi arrivano già decisi.** Qui non si ricalcola niente dal
 *    listino: le righe sono quelle che il chiamante ha davvero incassato,
 *    altrimenti incassato e fatturato divergono.
 * 3. **Non si tocca Fatture in Cloud.** Si arriva alla bozza e ci si ferma.
 *
 * @phpstan-type CustomerData array<string, string|null>
 */
class SubscriptionInvoiceImporter
{
    /**
     * Natura delle righe generate da qui, per distinguerle in fattura da
     * consulenza, anticipi e rimborsi.
     */
    public const LINE_KIND = 'subscription';

    /**
     * @param  array<string, mixed>  $data  Corpo della richiesta, già validato.
     */
    public function import(array $data): SubscriptionInvoiceResult
    {
        try {
            return DB::transaction(fn (): SubscriptionInvoiceResult => $this->store($data));
        } catch (UniqueConstraintViolationException) {
            // Due chiamate contemporanee per lo stesso pagamento: la nostra ha
            // perso la corsa. Al secondo giro la fattura dell'altra c'è già.
            return DB::transaction(fn (): SubscriptionInvoiceResult => $this->store($data));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(array $data): SubscriptionInvoiceResult
    {
        $existing = Invoice::query()
            ->where('source', $data['source'])
            ->where('source_id', $data['source_id'])
            ->lockForUpdate()
            ->first();

        if ($existing?->isSentToFic()) {
            throw SubscriptionInvoiceException::alreadySent($existing);
        }

        /** @var array<string, mixed> $customer */
        $customer = $data['customer'];
        [$client, $clientCreated] = $this->resolveClient($customer);

        $invoice = $existing ?? new Invoice;
        $invoice->fill($this->attributes($data, $client));
        $invoice->save();

        // Finché la fattura è solo nostra le righe si riscrivono per intero:
        // è quello che rende utile un secondo invio con i dati corretti.
        $invoice->items()->delete();
        $invoice->items()->createMany($this->items((array) $data['lines']));

        $invoice->load(['client', 'items']);

        return new SubscriptionInvoiceResult(
            invoice: $invoice,
            created: $existing === null,
            clientCreated: $clientCreated,
            warnings: $this->warnings($client),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Client $client): array
    {
        return [
            'user_id' => $this->ownerId(),
            'client_id' => $client->getKey(),
            // La numerazione la assegna FIC al momento dell'invio: qui resta vuota.
            'number' => null,
            'type' => Invoice::TYPE_INVOICE,
            'issue_date' => $data['issued_at'],
            'period_from' => $data['period']['from'],
            'period_to' => $data['period']['to'],
            'hourly_rate' => null,
            'vat_rate' => $data['vat_rate'] ?? $client->vat_rate ?? 22,
            // Il denaro è arrivato prima della fattura: nasce già incassata, o
            // su FIC finirebbe come "da pagare" e andrebbe saldata a mano.
            'status' => ($data['paid'] ?? true) ? 'paid' : 'draft',
            'notes' => $data['notes'] ?? null,
            'subject' => $data['subject'] ?? null,
            'ei_payment_method' => $data['ei_payment_method'] ?? null,
            'source' => $data['source'],
            'source_id' => $data['source_id'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function items(array $lines): array
    {
        return array_values(array_map(fn (array $line, int $index): array => [
            'name' => $line['name'],
            'description' => $line['description'] ?? null,
            'qty' => $line['qty'] ?? 1,
            'measure' => $line['measure'] ?? null,
            'net_price' => $line['net_price'],
            // Abbonamento software: IVA ordinaria, mai art. 15.
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => self::LINE_KIND,
            'sort' => $index,
        ], $lines, array_keys($lines)));
    }

    /**
     * Cliente corrispondente alla P.IVA, creandolo se non c'è.
     *
     * Su un cliente che esiste già riempiamo solo i campi vuoti: se è anche un
     * cliente di consulenza, la sua anagrafica è più curata di quella che
     * arriva da un checkout, e sovrascriverla sarebbe una perdita di dati.
     *
     * @param  array<string, mixed>  $customer
     * @return array{0: Client, 1: bool}
     */
    private function resolveClient(array $customer): array
    {
        $variants = VatNumber::variants((string) $customer['vat_number']);

        $client = Client::query()
            ->whereIn('vat_number', $variants)
            ->orderBy('id')
            ->first();

        if ($client !== null) {
            $this->fillBlanks($client, $customer);

            return [$client, false];
        }

        $client = Client::create([
            'name' => $customer['name'],
            'entity_type' => $customer['entity_type'] ?? 'company',
            'vat_number' => VatNumber::normalize((string) $customer['vat_number']),
            'tax_code' => $customer['tax_code'] ?? null,
            'address_street' => $customer['address_street'] ?? null,
            'address_postal_code' => $customer['address_postal_code'] ?? null,
            'address_city' => $customer['address_city'] ?? null,
            'address_province' => $customer['address_province'] ?? null,
            'country' => 'Italia',
            'country_iso' => 'IT',
            'email' => $customer['email'] ?? null,
            'certified_email' => $customer['certified_email'] ?? null,
            'ei_code' => $customer['ei_code'] ?? null,
            // Emettibile da qui: senza questo il pulsante "Invia a Fatture in
            // Cloud" non comparirebbe sulla fattura appena creata.
            'invoicing_provider' => Client::PROVIDER_FIC,
        ]);

        return [$client, true];
    }

    /**
     * Completa i soli campi anagrafici ancora vuoti. Non tocca mai il nome né
     * la configurazione di fatturazione: le fatture da abbonamento arrivano
     * con le righe già decise e non passano dal motore ricorrente.
     *
     * @param  array<string, mixed>  $customer
     */
    private function fillBlanks(Client $client, array $customer): void
    {
        $fields = [
            'tax_code', 'address_street', 'address_postal_code', 'address_city',
            'address_province', 'email', 'certified_email', 'ei_code',
        ];

        foreach ($fields as $field) {
            if (blank($client->{$field}) && filled($customer[$field] ?? null)) {
                $client->{$field} = $customer[$field];
            }
        }

        if ($client->isDirty()) {
            $client->save();
        }
    }

    /**
     * Segnalazioni che non impediscono la fattura ma che chi integra deve
     * poter vedere nei propri log.
     *
     * @return array<int, string>
     */
    private function warnings(Client $client): array
    {
        $warnings = [];

        if (! $client->isBillableHere()) {
            $warnings[] = sprintf(
                'Il cliente "%s" è configurato su %s: la fattura è stata creata ma da TrackFlow non è inviabile a Fatture in Cloud.',
                $client->name,
                $client->invoicingProviderLabel(),
            );
        }

        if (blank($client->ei_code) && blank($client->certified_email)) {
            $warnings[] = sprintf(
                'Il cliente "%s" non ha né codice destinatario né PEC: la fattura elettronica non potrà essere recapitata.',
                $client->name,
            );
        }

        return $warnings;
    }

    /**
     * Utente a cui intestare la fattura: l'amministratore, come già fanno gli
     * altri import automatici (vedi ImportFicInvoices).
     */
    private function ownerId(): int
    {
        $id = User::query()->where('role', 'admin')->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($id === null) {
            throw SubscriptionInvoiceException::noOwner();
        }

        return (int) $id;
    }
}
