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
});

/**
 * Registra le risposte FIC per un singolo documento ricevuto. La fake sta nel
 * test (non nel beforeEach) così ogni caso può variarne i campi.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeReceivedDocument(array $overrides = []): void
{
    $detail = array_merge([
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
    ], $overrides);

    Http::fake([
        '*/received_documents/*' => Http::response(['data' => $detail]),
        '*/received_documents*' => fn ($request) => str_contains($request->url(), 'page=1')
            ? Http::response(['data' => [['id' => $detail['id']]]])
            : Http::response(['data' => []]),
    ]);
}

it('imports received documents as passive invoices, creating the supplier', function () {
    fakeReceivedDocument();
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
    fakeReceivedDocument();
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();

    expect(PassiveInvoice::where('fic_document_id', 555)->count())->toBe(1);
    expect(Supplier::where('vat_number', '05555555555')->count())->toBe(1);
});

it('skips documents whose supplier is not found without --create-suppliers', function () {
    fakeReceivedDocument();
    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense'])->assertSuccessful();

    expect(PassiveInvoice::count())->toBe(0);
});

it('uses the supplier invoice_number as the passive number when present', function () {
    // Sui documenti ricevuti il numero vero è in invoice_number; numeration/number
    // sono vuoti, quindi senza questo campo si ripiegava sull'id FIC.
    fakeReceivedDocument([
        'id' => 777, 'number' => null, 'invoice_number' => 'FT/2025/0042', 'date' => '2025-11-10',
    ]);

    $this->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();

    expect(PassiveInvoice::where('fic_document_id', 777)->value('number'))->toBe('FT/2025/0042');
});
