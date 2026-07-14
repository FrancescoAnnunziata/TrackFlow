<?php

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\PassiveInvoices\Pages\ViewPassiveInvoice;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reconciles a passive invoice to a bank movement from its view page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::create(['name' => 'Fornitore SRL']);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => '12/2026', 'type' => 'expense',
        'document_date' => '2026-03-01', 'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-05', 'amount' => -122.00,
        'direction' => 'out', 'description' => 'Bonifico fornitore', 'dedup_hash' => 'd1',
    ]);

    Livewire::test(ViewPassiveInvoice::class, ['record' => $passive->getRouteKey()])
        ->callAction(TestAction::make('reconcileDocument'), [
            'bank_transaction_id' => $tx->id, 'amount' => 122.00,
        ])
        ->assertHasNoActionErrors();

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
});

it('lists the linked reconciliations on an active invoice view page', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $client = Client::create(['name' => 'Acme', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '3/2026',
        'issue_date' => '2026-03-01', 'period_from' => '2026-03-01', 'period_to' => '2026-03-31',
        'vat_rate' => 0, 'status' => 'sent', 'type' => Invoice::TYPE_INVOICE,
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Servizi', 'qty' => 1, 'net_price' => 500, 'vat_kind' => InvoiceItem::VAT_STANDARD]);
    $invoice->load('items');
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-05', 'amount' => $invoice->total(),
        'direction' => 'in', 'description' => 'Incasso cliente', 'dedup_hash' => 'd2',
    ]);
    app(ReconciliationService::class)->attach($tx, $invoice, $invoice->total());

    // Con la fattura interamente riconciliata, il reconcile è nascosto ma la
    // lista delle riconciliazioni è disponibile e si apre senza errori.
    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionHidden('reconcileDocument')
        ->assertActionVisible('viewDocumentReconciliations')
        ->callAction(TestAction::make('viewDocumentReconciliations'))
        ->assertHasNoActionErrors();
});
