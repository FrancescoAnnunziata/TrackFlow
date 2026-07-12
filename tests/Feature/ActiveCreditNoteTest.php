<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reporting\FinancialOverviewBuilder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Scenario Fedespedi: fattura consulenza 3.800 + costi vivi 313,60 (IVA 22% →
 * totale 5.018,59); nota di credito che storna i costi vivi (313,60 + IVA →
 * 382,59). Il cliente paga solo la consulenza: 4.636,00.
 *
 * @return array{0: Invoice, 1: Invoice, 2: Client}
 */
function fedespediScenario(bool $linked = true): array
{
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Fedespedi', 'invoicing_provider' => Client::PROVIDER_FIC, 'vat_rate' => 22]);

    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '3/2025',
        'type' => Invoice::TYPE_INVOICE, 'issue_date' => '2025-09-28',
        'period_from' => '2025-09-01', 'period_to' => '2025-09-30', 'vat_rate' => 22, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Consulenza Settembre', 'qty' => 1, 'net_price' => 3800, 'vat_kind' => InvoiceItem::VAT_STANDARD]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Rimborso costi vivi', 'qty' => 1, 'net_price' => 313.60, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    $cn = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'NC-1/2025',
        'type' => Invoice::TYPE_CREDIT_NOTE, 'related_invoice_id' => $linked ? $invoice->id : null,
        'issue_date' => '2025-12-15', 'period_from' => '2025-12-15', 'period_to' => '2025-12-15', 'vat_rate' => 22, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $cn->id, 'name' => 'Storno costi vivi', 'qty' => 1, 'net_price' => 313.60, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    return [$invoice->load('items'), $cn->load('items'), $client];
}

it('reduces the amount to collect by the linked credit note', function () {
    [$invoice] = fedespediScenario();

    expect($invoice->total())->toBe(5018.59);
    expect($invoice->creditedAmount())->toBe(382.59);
    expect($invoice->amountToCollect())->toBe(4636.00);
});

it('marks the invoice paid when the payment covers total minus the credit note', function () {
    [$invoice] = fedespediScenario();
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2025-10-09', 'amount' => 4636,
        'description' => 'Accredito Fedespedi quota consulenza', 'dedup_hash' => 'f1',
    ]);

    app(ReconciliationService::class)->attach($tx, $invoice, 4636);

    expect($invoice->fresh()->status)->toBe('paid');
    expect($tx->fresh()->reconciled)->toBeTrue();
});

it('counts an active credit note as negative revenue and output VAT', function () {
    fedespediScenario();

    $builder = app(FinancialOverviewBuilder::class);
    $set = $builder->g8labsMonthly(2025);
    $sept = collect($set)->firstWhere('mese', 9);   // fattura
    $dec = collect($set)->firstWhere('mese', 12);   // nota di credito

    expect($sept['ricavi'])->toBe(4113.60);
    expect($dec['ricavi'])->toBe(-313.60);
    expect($dec['iva_debito'])->toBe(-68.99);
    // Totale anno: la NC storna il ricavo dei costi vivi.
    expect(round(array_sum(array_column($set, 'ricavi')), 2))->toBe(3800.00);
});

it('links a credit note to its invoice from the action and re-collects', function () {
    [$invoice, $cn] = fedespediScenario(linked: false);
    $this->actingAs($invoice->user);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2025-10-09', 'amount' => 4636, 'description' => 'x', 'dedup_hash' => 'f2']);

    // Incasso registrato PRIMA del collegamento: non basta a coprire i 5.018,59.
    app(ReconciliationService::class)->attach($tx, $invoice, 4636);
    expect($invoice->fresh()->status)->toBe('sent');

    // Collego la NC alla fattura: l'importo da incassare scende a 4.636 → pagata.
    Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('collega_fattura')->table($cn), ['related_invoice_id' => $invoice->id]);

    expect($cn->fresh()->related_invoice_id)->toBe($invoice->id);
    expect($invoice->fresh()->status)->toBe('paid');
});

it('excludes credit notes from the receivables snapshot and nets the invoice', function () {
    fedespediScenario();

    $snap = app(FinancialOverviewBuilder::class)->g8labsSnapshot();
    // Credito = solo l'importo da incassare della fattura (4.636), la NC non è un credito.
    expect($snap['crediti'])->toBe(4636.00);
});
