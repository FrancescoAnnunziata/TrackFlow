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

    $detail = [
        'id' => 999,
        'numeration' => '',
        'number' => 14,
        'date' => '2026-06-14',
        'token' => 'doc-tok',
        'entity' => ['vat_number' => '01234567890'],
        'items_list' => [
            ['name' => 'Consulenza', 'qty' => 1, 'net_price' => 1800, 'measure' => '', 'vat' => ['id' => 0, 'value' => 22]],
            ['name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 143.5, 'measure' => '', 'vat' => ['id' => 32, 'value' => 0]],
        ],
    ];

    Http::fake([
        '*/issued_documents/*' => Http::response(['data' => $detail]),
        '*/issued_documents*' => function ($request) {
            return str_contains($request->url(), 'page=1')
                ? Http::response(['data' => [['id' => 999]]])
                : Http::response(['data' => []]);
        },
    ]);
});

it('imports issued invoices and maps them to the client by VAT number', function () {
    Client::create(['name' => 'Fedespedi', 'vat_number' => '01234567890', 'vat_rate' => 22]);

    $this->artisan('fic:import-invoices')->assertSuccessful();

    $invoice = Invoice::where('fic_document_id', 999)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->imported)->toBeTrue();
    expect($invoice->number)->toBe('14/2026');
    expect($invoice->items)->toHaveCount(2);
    // La riga rimborsi (vat id 32) è marcata art.15.
    expect($invoice->items->firstWhere('name', 'Rimborsi spese')->vat_kind)->toBe('art15');
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
