<?php

use App\Filament\Pages\FattureEmessePdf;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Jobs\ExtractIssuedInvoicesJob;
use App\Models\Client;
use App\Models\Hour;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Riga di revisione come la produce il job di estrazione, pronta per "crea".
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extractedRow(int $clientId, array $overrides = []): array
{
    return array_merge([
        'attachment' => 'issued-invoice-pdfs/fattura.pdf',
        'extracted_client' => 'QUISTO SRL',
        'client_id' => $clientId,
        'number' => '23/2026',
        'issue_date' => '2026-08-01',
        'period_from' => '2026-08-01',
        'period_to' => '2026-08-31',
        'vat_rate' => 0,
        'total' => 1000.0,
        'is_credit_note' => false,
        'lines' => [
            (string) Str::uuid() => ['name' => 'Manutenzione Software', 'qty' => 20, 'net_price' => 50],
        ],
    ], $overrides);
}

function fiscozenClient(string $name = 'Quisto'): Client
{
    return Client::create(['name' => $name, 'invoicing_provider' => Client::PROVIDER_FISCOZEN, 'vat_rate' => 0]);
}

it('creates an issued invoice with its lines, attachment and no VAT', function () {
    $client = fiscozenClient();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id)]])
        ->assertActionVisible('crea')
        ->call('crea');

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();

    expect($invoice->number)->toBe('23/2026');
    expect($invoice->status)->toBe('sent');
    expect($invoice->type)->toBe(Invoice::TYPE_INVOICE);
    expect($invoice->imported)->toBeTrue();
    expect($invoice->attachment)->toBe('issued-invoice-pdfs/fattura.pdf');
    expect($invoice->issue_date->toDateString())->toBe('2026-08-01');
    expect($invoice->items)->toHaveCount(1);
    expect($invoice->items->first()->qty)->toEqual(20.0);
    expect($invoice->items->first()->net_price)->toEqual(50.0);
    // Forfettario: nessuna IVA, il totale è l'imponibile.
    expect($invoice->fresh('items')->total())->toBe(1000.0);
});

it('skips an invoice already present for the same client and number', function () {
    $client = fiscozenClient();
    $this->actingAs(User::factory()->admin()->create());

    $row = [(string) Str::uuid() => extractedRow($client->id)];

    Livewire::test(FattureEmessePdf::class)->fillForm(['rows' => $row])->assertActionVisible('crea')
        ->call('crea');
    // Stesso PDF ricaricato: nessun doppione.
    Livewire::test(FattureEmessePdf::class)->fillForm(['rows' => $row])->assertActionVisible('crea')
        ->call('crea');

    expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
});

it('imports a credit note as a separate type, so the same number can coexist', function () {
    $client = fiscozenClient();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [
            (string) Str::uuid() => extractedRow($client->id),
            (string) Str::uuid() => extractedRow($client->id, ['is_credit_note' => true]),
        ]])
        ->assertActionVisible('crea')
        ->call('crea');

    expect(Invoice::where('client_id', $client->id)->count())->toBe(2);
    expect(Invoice::where('type', Invoice::TYPE_CREDIT_NOTE)->count())->toBe(1);
});

it('still creates the invoice when the PDF total disagrees with the lines, but says so', function () {
    $client = fiscozenClient();
    $this->actingAs(User::factory()->admin()->create());

    // Righe da 1.000 ma totale dichiarato 1.500: estrazione da controllare.
    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id, ['total' => 1500.0])]])
        ->assertActionVisible('crea')
        ->call('crea')
        ->assertNotified();

    expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
});

it('matches the client from the PDF name even when the anagrafica uses a short form', function () {
    $quisto = fiscozenClient('Quisto');
    fiscozenClient('Dolcitalia');

    // "QUISTO SRL" sul PDF, "Quisto" in anagrafica.
    expect(ExtractIssuedInvoicesJob::matchClient('QUISTO SRL', null, null)?->id)->toBe($quisto->id);
    // Il codice vince sul nome.
    $quisto->update(['vat_number' => '12030360965']);
    expect(ExtractIssuedInvoicesJob::matchClient('Nome Sbagliato', '12030360965', null)?->id)->toBe($quisto->id);
    // Nessuna corrispondenza: si lascia scegliere all'utente.
    expect(ExtractIssuedInvoicesJob::matchClient('Azienda Ignota', null, null))->toBeNull();
});

it('renders the review table, including the nested lines repeater', function () {
    $client = fiscozenClient();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id)]])
        ->assertSuccessful()
        ->assertSee('Rivedi i dati estratti')
        // Il nome letto dal PDF resta visibile accanto al cliente scelto.
        ->assertSee('QUISTO SRL')
        // Il repeater annidato delle righe è renderizzato (i valori dei campi
        // sono legati via Alpine, quindi si verifica la struttura).
        ->assertSee('Righe')
        ->assertSee('Descrizione')
        ->assertSee('Prezzo unit.');
});

it('overwrites the TrackFlow draft with the PDF data, number included', function () {
    $client = fiscozenClient();
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    // Bozza preparata in TrackFlow: nessun numero, lo assegna Fiscozen.
    $draft = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'issue_date' => '2026-07-31', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
        'vat_rate' => 0, 'status' => 'draft',
    ]);
    InvoiceItem::create(['invoice_id' => $draft->id, 'name' => 'Stima consulenza', 'qty' => 1, 'net_price' => 1000, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id, ['replaces_invoice_id' => $draft->id])]])
        ->call('crea');

    // Una sola fattura: la bozza è stata aggiornata, non duplicata.
    expect(Invoice::where('client_id', $client->id)->count())->toBe(1);

    $draft->refresh()->load('items');
    expect($draft->number)->toBe('23/2026');
    expect($draft->status)->toBe('sent');
    expect($draft->imported)->toBeTrue();
    expect($draft->attachment)->toBe('issued-invoice-pdfs/fattura.pdf');
    // Le righe sono quelle del PDF, non più la stima.
    expect($draft->items)->toHaveCount(1);
    expect($draft->items->first()->name)->toBe('Manutenzione Software');
    expect($draft->total())->toBe(1000.0);
});

it('keeps the hours linked to the draft it overwrites', function () {
    $client = fiscozenClient();
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $draft = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'issue_date' => '2026-07-31', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
        'vat_rate' => 0, 'status' => 'draft',
    ]);
    $hour = Hour::create(['user_id' => $user->id, 'date' => '2026-07-10', 'hours' => 8, 'billable' => true]);
    $draft->hours()->attach($hour->id);

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id, ['replaces_invoice_id' => $draft->id])]])
        ->call('crea');

    expect($draft->refresh()->hours)->toHaveCount(1);
});

it('proposes the draft to overwrite only when its total matches the PDF', function () {
    $client = fiscozenClient();
    $user = User::factory()->admin()->create();

    $makeDraft = function (float $amount) use ($client, $user): Invoice {
        $draft = Invoice::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'issue_date' => '2026-07-31', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
            'vat_rate' => 0, 'status' => 'draft',
        ]);
        InvoiceItem::create(['invoice_id' => $draft->id, 'name' => 'Stima', 'qty' => 1, 'net_price' => $amount, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

        return $draft;
    };

    $other = $makeDraft(700.0);
    expect(ExtractIssuedInvoicesJob::matchDraft($client->id, Invoice::TYPE_INVOICE, 1000.0))->toBeNull();

    $matching = $makeDraft(1000.0);
    expect(ExtractIssuedInvoicesJob::matchDraft($client->id, Invoice::TYPE_INVOICE, 1000.0)?->id)->toBe($matching->id);

    // Le fatture già importate non sono candidate: rappresentano documenti reali.
    $matching->update(['imported' => true]);
    expect(ExtractIssuedInvoicesJob::matchDraft($client->id, Invoice::TYPE_INVOICE, 1000.0))->toBeNull();
    expect($other->exists)->toBeTrue();
});

it('does not overwrite a draft when another invoice already uses that number', function () {
    $client = fiscozenClient();
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    // Il numero 23/2026 esiste già su un'altra fattura dello stesso cliente.
    Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '23/2026',
        'issue_date' => '2026-08-01', 'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        'vat_rate' => 0, 'status' => 'sent', 'imported' => true,
    ]);
    $draft = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'issue_date' => '2026-07-31', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
        'vat_rate' => 0, 'status' => 'draft',
    ]);

    Livewire::test(FattureEmessePdf::class)
        ->fillForm(['rows' => [(string) Str::uuid() => extractedRow($client->id, ['replaces_invoice_id' => $draft->id])]])
        ->call('crea');

    // La bozza resta intatta: nessuna sovrascrittura che violerebbe l'unique.
    expect($draft->refresh()->number)->toBeNull();
    expect($draft->status)->toBe('draft');
    expect(Invoice::where('client_id', $client->id)->count())->toBe(2);
});

it('says who assigns the invoice number, depending on the client', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $fiscozen = fiscozenClient();
    $fic = Client::create(['name' => 'Fedespedi', 'invoicing_provider' => Client::PROVIDER_FIC, 'vat_rate' => 22]);

    $make = fn (Client $client): Invoice => Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'issue_date' => '2026-08-01', 'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        'vat_rate' => 0, 'status' => 'draft',
    ]);

    // Cliente Fiscozen: la numerazione non la decide Fatture in Cloud.
    Livewire::test(EditInvoice::class, ['record' => $make($fiscozen)->getRouteKey()])
        ->assertSee('Lo assegna Fiscozen')
        ->assertDontSee('lo assegna Fatture in Cloud al momento dell\'invio');

    // Cliente FIC: resta il testo di prima.
    Livewire::test(EditInvoice::class, ['record' => $make($fic)->getRouteKey()])
        ->assertSee('lo assegna Fatture in Cloud al momento dell\'invio');
});
