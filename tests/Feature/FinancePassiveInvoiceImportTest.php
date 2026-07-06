<?php

use App\Models\FicCredential;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.fic', [
        'client_id' => 'x', 'client_secret' => 'y', 'redirect' => 'z',
        'base_url' => 'https://api-v2.fattureincloud.it', 'scopes' => 's',
    ]);

    FicCredential::create([
        'access_token' => 'a/valid', 'refresh_token' => 'r/valid',
        'expires_at' => now()->addHour(), 'company_id' => '123',
    ]);

    User::factory()->admin()->create();

    $detail = [
        'id' => 555,
        'numeration' => '',
        'number' => 3,
        'date' => '2026-06-20',
        'due_date' => '2026-07-20',
        'token' => 'rec-tok',
        'amount_net' => 200,
        'amount_vat' => 44,
        'amount_gross' => 244,
        'category' => 'Servizi',
        'entity' => ['name' => 'Fornitore ACME', 'vat_number' => '05555555555'],
        'items_list' => [
            ['name' => 'Hosting', 'qty' => 1, 'net_price' => 200, 'measure' => '', 'vat' => ['id' => 0, 'value' => 22]],
        ],
    ];

    Http::fake([
        '*/received_documents/*' => Http::response(['data' => $detail]),
        '*/received_documents*' => function ($request) {
            return str_contains($request->url(), 'page=1')
                ? Http::response(['data' => [['id' => 555]]])
                : Http::response(['data' => []]);
        },
    ]);
});

it('imports received documents as passive invoices, creating the supplier', function () {
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])
        ->assertSuccessful();

    $passive = PassiveInvoice::where('fic_document_id', 555)->first();
    expect($passive)->not->toBeNull();
    expect($passive->number)->toBe('3/2026');
    expect((float) $passive->amount_gross)->toBe(244.0);
    expect($passive->items)->toHaveCount(1);
    expect((float) $passive->items->first()->vat_amount)->toBe(44.0);

    $supplier = Supplier::where('vat_number', '05555555555')->first();
    expect($supplier)->not->toBeNull();
    expect($supplier->name)->toBe('Fornitore ACME');
});

it('is idempotent on re-run', function () {
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();

    expect(PassiveInvoice::where('fic_document_id', 555)->count())->toBe(1);
    expect(Supplier::where('vat_number', '05555555555')->count())->toBe(1);
});

it('skips documents whose supplier is not found without --create-suppliers', function () {
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense'])->assertSuccessful();

    expect(PassiveInvoice::count())->toBe(0);
});
