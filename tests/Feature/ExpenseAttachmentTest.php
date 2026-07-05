<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the expense view with both image and pdf attachments', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Acme']);
    $expense = Expense::create([
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'date' => now(),
        'amount' => 42,
        'attachaments' => ['expense-attachaments/foto.jpg', 'expense-attachaments/ricevuta.pdf'],
    ]);

    $this->actingAs($admin)
        ->get('/expenses/'.$expense->id)
        ->assertOk()
        ->assertSee('Apri PDF');

    $this->actingAs($admin)->get('/expenses/create')->assertOk();
});

it('labels a pdf attachment as PDF in the invoice notes', function () {
    $client = Client::create(['name' => 'Acme', 'vat_number' => '123', 'vat_rate' => 22]);
    $invoice = Invoice::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'number' => '1/2026',
        'issue_date' => now(),
        'period_from' => now()->startOfMonth(),
        'period_to' => now()->endOfMonth(),
        'vat_rate' => 22,
        'status' => 'draft',
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Rimborsi spese', 'qty' => 1, 'net_price' => 30, 'vat_kind' => 'art15', 'line_kind' => 'expenses']);

    $expense = Expense::create([
        'user_id' => $invoice->user_id,
        'client_id' => $client->id,
        'date' => now(),
        'amount' => 30,
        'notes' => 'pedaggio',
        'attachaments' => ['expense-attachaments/ricevuta.pdf'],
    ]);
    $invoice->expenses()->attach($expense->id);

    $notes = $invoice->fresh()->toFicPayload()['data']['notes'];

    expect($notes)->toContain('>PDF</a>');
    expect($notes)->toContain('ricevuta.pdf');
});
