<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\InvoiceExpenseExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('parses inline detail lines into amounts, ignoring embedded dates', function () {
    $extractor = new InvoiceExpenseExtractor();

    $parts = $extractor->parseDetail("Trenitalia: 99€\nPranzo 12/01 23,50\nPranzo 21/01 23,50\npranzo 30/01 36");

    expect($parts)->toHaveCount(4);
    expect(array_column($parts, 'amount'))->toBe([99.0, 23.5, 23.5, 36.0]);
    expect($parts[0]['label'])->toBe('Trenitalia');
});

it('parses the FiC HTML notes table into per-row amounts', function () {
    $extractor = new InvoiceExpenseExtractor();

    $html = '<table><tbody>'
        .'<tr><td>Data</td><td>importo</td><td>note</td></tr>'
        .'<tr><td>4 maggio</td><td>23,5</td><td>pranzo</td></tr>'
        .'<tr><td>6. maggio</td><td>90</td><td>trenitalia</td></tr>'
        .'<tr><td>7 maggio</td><td>30</td><td>pranzo</td></tr>'
        .'</tbody></table>';

    $parts = $extractor->parseDetail($html);

    expect($parts)->toHaveCount(3); // l'intestazione (senza importo) è saltata
    expect(array_column($parts, 'amount'))->toBe([23.5, 90.0, 30.0]);
    expect($parts[1]['label'])->toContain('trenitalia');
});

it('infers the conto from keywords', function () {
    $extractor = new InvoiceExpenseExtractor();

    expect($extractor->contoFromText('Trenitalia'))->toBe('Trasferte');
    expect($extractor->contoFromText('Pranzo per trasferta'))->toBe('Trasferte'); // "trasfert" vince
    expect($extractor->contoFromText('Ristorante Yang'))->toBe('Ristorazione');
    expect($extractor->contoFromText('Rimborsi spese'))->toBeNull();
});

it('itemizes a re-charge line when parsed amounts match the total', function () {
    $client = Client::create(['name' => 'Cliente FiC', 'invoicing_provider' => 'fatture_in_cloud']);
    $user = User::factory()->admin()->create();
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '5/2026',
        'issue_date' => '2026-02-01', 'period_from' => '2026-02-01', 'period_to' => '2026-02-01',
        'vat_rate' => 22, 'status' => 'sent',
    ]);
    $invoice->items()->create([
        'name' => 'Rimborsi spese', 'description' => "Trenitalia: 99€\nPranzo 12/01 23,50\nPranzo 21/01 23,50\npranzo 30/01 36",
        'qty' => 1, 'net_price' => 182, 'vat_kind' => 'art15', 'line_kind' => 'consulting', 'sort' => 0,
    ]);

    $proposal = app(InvoiceExpenseExtractor::class)->proposals()->firstWhere('invoice.id', $invoice->id);

    expect($proposal['expenses'])->toHaveCount(4);
    expect(collect($proposal['expenses'])->every(fn ($e) => $e['itemized']))->toBeTrue();
    expect(round(collect($proposal['expenses'])->sum('amount'), 2))->toBe(182.0);
    expect($proposal['expenses'][0]['conto'])->toBe('Trasferte');
});

it('falls back to a single lump expense when detail is absent (Vedi note)', function () {
    $client = Client::create(['name' => 'Cliente FiC', 'invoicing_provider' => 'fatture_in_cloud']);
    $user = User::factory()->admin()->create();
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '6/2026',
        'issue_date' => '2026-03-07', 'period_from' => '2026-03-07', 'period_to' => '2026-03-07',
        'vat_rate' => 22, 'status' => 'sent', 'notes' => '',
    ]);
    $invoice->items()->create([
        'name' => 'Rimborsi spese', 'description' => 'Vedi note',
        'qty' => 1, 'net_price' => 107, 'vat_kind' => 'art15', 'line_kind' => 'consulting', 'sort' => 0,
    ]);

    $proposal = app(InvoiceExpenseExtractor::class)->proposals()->firstWhere('invoice.id', $invoice->id);

    expect($proposal['expenses'])->toHaveCount(1);
    expect($proposal['expenses'][0]['itemized'])->toBeFalse();
    expect($proposal['expenses'][0]['amount'])->toBe(107.0);
});

it('uses invoice notes for the detail when the line points to them', function () {
    $client = Client::create(['name' => 'Cliente FiC', 'invoicing_provider' => 'fatture_in_cloud']);
    $user = User::factory()->admin()->create();
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '10/2026',
        'issue_date' => '2026-04-06', 'period_from' => '2026-04-06', 'period_to' => '2026-04-06',
        'vat_rate' => 22, 'status' => 'sent',
        'notes' => "Trenitalia 200,00\nHotel 84,60",
    ]);
    $invoice->items()->create([
        'name' => 'Rimborsi spese', 'description' => 'Vedi note',
        'qty' => 1, 'net_price' => 284.60, 'vat_kind' => 'art15', 'line_kind' => 'consulting', 'sort' => 0,
    ]);

    $proposal = app(InvoiceExpenseExtractor::class)->proposals()->firstWhere('invoice.id', $invoice->id);

    expect($proposal['expenses'])->toHaveCount(2);
    expect(round(collect($proposal['expenses'])->sum('amount'), 2))->toBe(284.60);
});

it('skips invoices that already have linked expenses and non-FiC clients', function () {
    $user = User::factory()->admin()->create();

    // Cliente Fiscozen: da ignorare.
    $fiscozen = Client::create(['name' => 'Cliente Fiscozen', 'invoicing_provider' => 'fiscozen']);
    $inv1 = Invoice::create([
        'user_id' => $user->id, 'client_id' => $fiscozen->id, 'number' => 'F1',
        'issue_date' => '2026-05-01', 'period_from' => '2026-05-01', 'period_to' => '2026-05-01',
        'vat_rate' => 22, 'status' => 'sent',
    ]);
    $inv1->items()->create(['name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 50, 'vat_kind' => 'art15', 'line_kind' => 'consulting', 'sort' => 0]);

    // Cliente FiC ma con spesa già collegata: da ignorare.
    $fic = Client::create(['name' => 'Cliente FiC', 'invoicing_provider' => 'fatture_in_cloud']);
    $inv2 = Invoice::create([
        'user_id' => $user->id, 'client_id' => $fic->id, 'number' => 'C1',
        'issue_date' => '2026-05-02', 'period_from' => '2026-05-02', 'period_to' => '2026-05-02',
        'vat_rate' => 22, 'status' => 'sent',
    ]);
    $inv2->items()->create(['name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 60, 'vat_kind' => 'art15', 'line_kind' => 'consulting', 'sort' => 0]);
    $existing = Expense::create(['user_id' => $user->id, 'client_id' => $fic->id, 'date' => '2026-05-02', 'amount' => 60]);
    $existing->invoices()->attach($inv2->id);

    expect(app(InvoiceExpenseExtractor::class)->proposals())->toBeEmpty();
});
