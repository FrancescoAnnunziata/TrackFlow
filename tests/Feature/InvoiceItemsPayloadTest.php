<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeItemsInvoice(): Invoice
{
    $client = Client::create([
        'name' => 'Fedespedi',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'vat_number' => '01234567890',
        'vat_rate' => 22,
        'consulting_label' => 'Consulenza digitale',
        'payment_method_id' => 2411414,
    ]);

    $invoice = Invoice::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'number' => '2026-014',
        'issue_date' => now()->setDate(2026, 6, 14),
        'period_from' => now()->setDate(2026, 6, 1),
        'period_to' => now()->setDate(2026, 6, 30),
        'vat_rate' => 22,
        'status' => 'draft',
    ]);

    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Consulenza Progetti', 'qty' => 1, 'net_price' => 1800, 'vat_kind' => 'standard', 'line_kind' => 'consulting', 'sort' => 1]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Consulenza supporto', 'qty' => 1, 'net_price' => 1800, 'vat_kind' => 'standard', 'line_kind' => 'consulting', 'sort' => 2]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 143.5, 'vat_kind' => 'art15', 'line_kind' => 'expenses', 'sort' => 3]);

    return $invoice->fresh();
}

it('computes totals excluding art.15 from the VAT base', function () {
    $invoice = makeItemsInvoice();

    expect($invoice->taxableAmount())->toBe(3600.0);        // 1800 + 1800
    expect($invoice->vatAmount())->toBe(792.0);             // 3600 * 22%
    expect($invoice->art15Total())->toBe(143.5);
    expect($invoice->total())->toBe(4535.5);                // 3600 + 792 + 143.5
});

it('builds a FIC payload with art.15 vat id on expense lines', function () {
    config()->set('services.fic.art15_vat_id', 32);
    $invoice = makeItemsInvoice();

    $payload = $invoice->toFicPayload()['data'];

    expect($payload['items_list'])->toHaveCount(3);
    // Consulenza: aliquota standard per valore.
    expect($payload['items_list'][0]['vat'])->toBe(['value' => 22.0]);
    // Rimborsi: id IVA art.15.
    expect($payload['items_list'][2]['vat'])->toBe(['id' => 32]);
    expect($payload['e_invoice'])->toBeFalse();
    expect($payload['visible_subject'])->toContain('Consulenza digitale');
    expect($payload['payment_method'])->toBe(['id' => 2411414]);
});

it('puts the expense detail with photo links in the notes', function () {
    $invoice = makeItemsInvoice();
    $client = $invoice->client;

    $expense = Expense::create([
        'user_id' => $invoice->user_id,
        'client_id' => $client->id,
        'date' => now()->setDate(2026, 6, 4),
        'amount' => 23.5,
        'notes' => 'pranzo',
        'attachaments' => ['expense-attachaments/receipt1.jpg'],
    ]);
    $invoice->expenses()->attach($expense->id);

    $notes = $invoice->fresh()->toFicPayload()['data']['notes'];

    expect($notes)->toContain('<table');
    expect($notes)->toContain('pranzo');
    expect($notes)->toContain('23,50');
    expect($notes)->toContain('receipt1.jpg');   // link al giustificativo
    expect($notes)->toContain('Giustificativo');
});

it('keeps the legacy payload for invoices without explicit items', function () {
    $client = Client::create(['name' => 'Legacy', 'vat_number' => '1', 'vat_rate' => 22]);
    $invoice = Invoice::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'number' => '2026-099',
        'issue_date' => now(),
        'period_from' => now()->startOfMonth(),
        'period_to' => now()->endOfMonth(),
        'hourly_rate' => 50,
        'vat_rate' => 22,
        'status' => 'draft',
    ]);

    // Nessuna riga esplicita → percorso legacy, subject "Periodo ...".
    $payload = $invoice->fresh()->toFicPayload()['data'];
    expect($payload['subject'])->toContain('Periodo');
    expect($payload['visible_subject'])->toBe('');
});
