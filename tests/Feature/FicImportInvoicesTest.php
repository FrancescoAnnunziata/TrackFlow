<?php

use App\Models\Client;
use App\Models\FicCredential;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.fic', [
        'client_id' => 'x', 'client_secret' => 'y', 'redirect' => 'z',
        'base_url' => 'https://api-v2.fattureincloud.it', 'scopes' => 's', 'art15_vat_id' => 32,
    ]);

    FicCredential::create([
        'access_token' => 'a/valid', 'refresh_token' => 'r/valid',
        'expires_at' => now()->addHour(), 'company_id' => '123',
    ]);

    User::factory()->admin()->create();

    $invoiceDetail = [
        'id' => 999,
        'numeration' => '',
        'number' => 14,
        'date' => '2026-06-14',
        'token' => 'doc-tok',
        'entity' => ['vat_number' => '01234567890'],
        'items_list' => [
            ['name' => 'Consulenza', 'qty' => 1, 'net_price' => 1800, 'measure' => '', 'vat' => ['id' => 0, 'value' => 22]],
            // ID art.15 reale dell'azienda FIC (≠ dal default in config): va
            // riconosciuta come esclusa art.15 per aliquota 0, non per ID.
            ['name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 143.5, 'measure' => '', 'vat' => ['id' => 13509782, 'value' => 0]],
        ],
    ];
    $creditNoteDetail = [
        'id' => 1000,
        'numeration' => 'NC',
        'number' => 2,
        'date' => '2026-06-20',
        'token' => 'nc-tok',
        'entity' => ['vat_number' => '01234567890'],
        'items_list' => [
            ['name' => 'Storno rimborsi spese', 'qty' => 1, 'net_price' => 143.5, 'measure' => '', 'vat' => ['id' => 0, 'value' => 22]],
        ],
    ];

    // Fatture e note di credito hanno id diversi e si distinguono per il
    // parametro `type` dell'elenco (come nell'API reale di Fatture in Cloud).
    Http::fake([
        '*/issued_documents/999*' => Http::response(['data' => $invoiceDetail]),
        '*/issued_documents/1000*' => Http::response(['data' => $creditNoteDetail]),
        '*/issued_documents*' => function ($request) {
            $url = $request->url();
            if (! str_contains($url, 'page=1')) {
                return Http::response(['data' => []]);
            }

            return str_contains($url, 'type=credit_note')
                ? Http::response(['data' => [['id' => 1000]]])
                : Http::response(['data' => [['id' => 999]]]);
        },
    ]);
});

it('imports issued invoices and maps them to the client by VAT number', function () {
    Client::create(['name' => 'Fedespedi', 'vat_number' => '01234567890', 'vat_rate' => 22]);

    $this->artisan('fic:import-invoices')->assertSuccessful();

    $invoice = Invoice::where('fic_document_id', 999)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->imported)->toBeTrue();
    expect($invoice->type)->toBe(Invoice::TYPE_INVOICE);
    expect($invoice->number)->toBe('14/2026');
    expect($invoice->items)->toHaveCount(2);
    // La riga rimborsi (aliquota 0) è marcata art.15, non standard.
    expect($invoice->items->firstWhere('name', 'Rimborsi spese')->vat_kind)->toBe('art15');
    // Totale corretto: 1.800 al 22% + 143,50 art.15 = 2.339,50 (l'art.15 NON prende IVA).
    expect($invoice->taxableAmount())->toBe(1800.0);
    expect($invoice->vatAmount())->toBe(396.0);
    expect($invoice->total())->toBe(2339.5);
});

it('imports issued credit notes with the credit_note type', function () {
    Client::create(['name' => 'Fedespedi', 'vat_number' => '01234567890', 'vat_rate' => 22]);

    $this->artisan('fic:import-invoices')->assertSuccessful();

    $cn = Invoice::where('fic_document_id', 1000)->first();
    expect($cn)->not->toBeNull();
    expect($cn->type)->toBe(Invoice::TYPE_CREDIT_NOTE);
    expect($cn->number)->toBe('NC 2/2026');
    // Nota di credito e fattura restano documenti distinti.
    expect(Invoice::where('fic_document_id', 999)->value('type'))->toBe(Invoice::TYPE_INVOICE);
});

it('is idempotent on re-run', function () {
    Client::create(['name' => 'Fedespedi', 'vat_number' => '01234567890', 'vat_rate' => 22]);

    $this->artisan('fic:import-invoices')->assertSuccessful();
    $this->artisan('fic:import-invoices')->assertSuccessful();

    expect(Invoice::where('fic_document_id', 999)->count())->toBe(1);
    expect(Invoice::find(Invoice::where('fic_document_id', 999)->value('id'))->items)->toHaveCount(2);
});

it('skips invoices whose client is not found', function () {
    // Nessun cliente con quella P.IVA.
    $this->artisan('fic:import-invoices')->assertSuccessful();

    expect(Invoice::count())->toBe(0);
});
