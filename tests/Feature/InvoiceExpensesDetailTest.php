<?php

use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Fattura con riga art. 15 e spese agganciate, come la genera InvoiceBuilder.
 *
 * @param  array<int, array{date: string, amount: float, conto?: string, notes?: string, attachaments?: array<int, string>}>  $expenses
 */
function invoiceWithExpenses(array $expenses): Invoice
{
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Fedespedi', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'issue_date' => '2026-08-01', 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
        'vat_rate' => 22, 'status' => 'draft',
    ]);

    $models = collect($expenses)->map(fn (array $e): Expense => Expense::create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'date' => $e['date'], 'amount' => $e['amount'],
        'conto' => $e['conto'] ?? null, 'notes' => $e['notes'] ?? null,
        'attachaments' => $e['attachaments'] ?? [],
    ]));

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'name' => 'Rimborsi spese', 'description' => 'Vedi note',
        'qty' => 1, 'net_price' => $models->sum('amount'), 'vat_kind' => InvoiceItem::VAT_ART15,
    ]);
    $invoice->expenses()->sync($models->pluck('id')->all());

    return $invoice->fresh(['items', 'expenses']);
}

it('shows the expense breakdown with attachment links on the invoice edit page', function () {
    $invoice = invoiceWithExpenses([
        ['date' => '2026-07-07', 'amount' => 47.00, 'conto' => 'Ristorazione', 'attachaments' => ['expense-attachaments/a.jpg']],
        ['date' => '2026-07-24', 'amount' => 52.34, 'notes' => 'Taxi Malpensa', 'attachaments' => ['expense-attachaments/b.pdf']],
        ['date' => '2026-07-31', 'amount' => 26.00],
    ]);
    $this->actingAs($invoice->user);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('Dettaglio rimborsi spese')
        // Le singole voci, non solo il totale della riga art. 15.
        ->assertSee('07/07/2026')
        ->assertSee('€47.00')
        ->assertSee('Ristorazione')
        ->assertSee('24/07/2026')
        ->assertSee('€52.34')
        ->assertSee('Taxi Malpensa')
        ->assertSee('31/07/2026')
        ->assertSee('€26.00')
        // Totale e link ai giustificativi.
        ->assertSee('€125.34')
        ->assertSee('expense-attachaments/a.jpg')
        ->assertSee('expense-attachaments/b.pdf')
        // Spesa senza giustificativo: va segnalata.
        ->assertSee('manca');
});

it('shows the expense breakdown on the invoice view page', function () {
    $invoice = invoiceWithExpenses([
        ['date' => '2026-07-07', 'amount' => 47.00, 'conto' => 'Ristorazione'],
    ]);
    $this->actingAs($invoice->user);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('Dettaglio rimborsi spese')
        ->assertSee('07/07/2026')
        ->assertSee('€47.00');
});

it('warns when the art. 15 line no longer matches the linked expenses', function () {
    $invoice = invoiceWithExpenses([
        ['date' => '2026-07-07', 'amount' => 47.00],
    ]);
    // Riga ritoccata a mano: 60,00 contro 47,00 di spese agganciate.
    $invoice->items()->first()->update(['net_price' => 60.00]);
    $this->actingAs($invoice->user);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('scarto € 13,00');
});

it('hides the section on invoices without linked expenses', function () {
    $invoice = invoiceWithExpenses([]);
    $this->actingAs($invoice->user);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertDontSee('Dettaglio rimborsi spese');
});
