<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reporting\RiconciliazioniAttiveBuilder;
use App\Services\Reporting\RiconciliazioniPassiveBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function periodo(): array
{
    return [Carbon::parse('2026-01-01')->startOfDay(), Carbon::parse('2026-12-31')->endOfDay()];
}

it('lists active invoices with their incasso movement and linked credit note', function () {
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Acme', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '5/2026',
        'issue_date' => '2026-03-01', 'period_from' => '2026-03-01', 'period_to' => '2026-03-31',
        'vat_rate' => 0, 'status' => 'sent', 'type' => Invoice::TYPE_INVOICE,
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Servizi', 'qty' => 1, 'net_price' => 1000, 'vat_kind' => InvoiceItem::VAT_STANDARD]);
    $cn = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'NC-5/2026',
        'related_invoice_id' => $invoice->id, 'type' => Invoice::TYPE_CREDIT_NOTE,
        'issue_date' => '2026-03-05', 'period_from' => '2026-03-05', 'period_to' => '2026-03-05', 'vat_rate' => 0, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $cn->id, 'name' => 'Storno', 'qty' => 1, 'net_price' => 200, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-10', 'amount' => 800,
        'direction' => 'in', 'description' => 'Incasso', 'dedup_hash' => 'ra1',
    ]);
    app(ReconciliationService::class)->attach($tx, $invoice->load('creditNotes.items'), 800);

    [$from, $to] = periodo();
    $table = app(RiconciliazioniAttiveBuilder::class)->build($from, $to);
    $rows = collect($table['rows'])->where('kind', 'data')->pluck('cells');

    // La nota di credito non è una riga a sé.
    expect($rows)->toHaveCount(1);
    $r = $rows->first();
    expect($r[0])->toBe('5/2026');
    expect($r[3])->toBe(1000.0);      // Totale
    expect($r[4])->toBe(800.0);       // Da incassare (1000 - 200 storno)
    expect($r[5])->toBe(800.0);       // Incassato
    expect($r[6])->toBe(0.0);         // Residuo
    expect($r[7])->toBe('Incassata'); // Stato
    expect($r[8])->toContain('InBank');
    expect($r[9])->toContain('NC-5/2026');
});

it('lists passive invoices with bank payment or reimbursement label', function () {
    $supplier = Supplier::create(['name' => 'Fornitore']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);

    // Pagata con bonifico.
    $bonificata = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'type' => 'expense', 'document_date' => '2026-02-01',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122, 'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-02-05', 'amount' => -122,
        'direction' => 'out', 'description' => 'Pagamento FP-1', 'dedup_hash' => 'rp1',
    ]);
    app(ReconciliationService::class)->attach($tx, $bonificata, 122);

    // Anticipata dal dipendente e chiusa da un rimborso.
    $rimborsata = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-2', 'type' => 'expense', 'document_date' => '2026-02-10',
        'amount_net' => 50, 'amount_vat' => 11, 'amount_gross' => 61, 'payment_status' => PassiveInvoice::STATUS_PAID,
    ]);
    $reimbursement = Reimbursement::create([
        'user_id' => User::factory()->create()->id, 'type' => 'trasferta', 'status' => 'paid',
        'date' => '2026-02-28', 'amount' => 61,
    ]);
    $rimborsata->update(['reimbursement_id' => $reimbursement->id]);

    [$from, $to] = periodo();
    $table = app(RiconciliazioniPassiveBuilder::class)->build($from, $to);
    $rows = collect($table['rows'])->where('kind', 'data')->keyBy(fn ($r) => $r['cells'][0]);

    expect($rows['FP-1']['cells'][6])->toBe('Pagata');
    expect($rows['FP-1']['cells'][7])->toContain('InBank');

    expect($rows['FP-2']['cells'][6])->toBe('Pagata (rimborso)');
    expect($rows['FP-2']['cells'][7])->toContain('Rimborso spese');
});
