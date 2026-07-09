<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Costo;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reporting\FinancialOverviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ficInvoice(int $net, int $vatRate, string $date): Invoice
{
    $client = Client::create(['name' => 'Cliente FiC', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $inv = Invoice::create([
        'user_id' => User::factory()->admin()->create()->id, 'client_id' => $client->id,
        'number' => '1/2026', 'issue_date' => $date, 'period_from' => $date, 'period_to' => $date,
        'vat_rate' => $vatRate, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $inv->id, 'name' => 'Servizi', 'qty' => 1, 'net_price' => $net, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    return $inv->load('items');
}

it('builds the G8LABS monthly view with revenue, costs, margin and VAT', function () {
    // Ricavo giugno: imponibile 1000, IVA 22% = 220.
    ficInvoice(1000, 22, '2026-06-10');

    // Costo giugno: fattura passiva netto 300 + IVA 66.
    $supplier = Supplier::create(['name' => 'Fornitore']);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'document_date' => '2026-06-15',
        'amount_net' => 300, 'amount_vat' => 66, 'amount_gross' => 366,
        'category' => 'Software e abbonamenti cloud', 'payment_status' => 'not_paid',
    ]);

    $rows = app(FinancialOverviewBuilder::class)->g8labsMonthly(2026);
    $giugno = collect($rows)->firstWhere('mese', 6);

    expect($giugno['ricavi'])->toBe(1000.0);
    expect($giugno['costi'])->toBe(300.0);
    expect($giugno['margine'])->toBe(700.0);
    expect($giugno['iva_debito'])->toBe(220.0);
    expect($giugno['iva_credito'])->toBe(66.0);
    expect($giugno['iva_saldo'])->toBe(154.0);
});

it('excludes credit notes as positive cost and counts them negative', function () {
    $supplier = Supplier::create(['name' => 'Amazon']);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP', 'type' => 'expense', 'document_date' => '2026-05-10',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122, 'category' => 'X', 'payment_status' => 'not_paid',
    ]);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'NC', 'type' => 'passive_credit_note', 'document_date' => '2026-05-11',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122, 'category' => 'X', 'payment_status' => 'not_paid',
    ]);

    $maggio = collect(app(FinancialOverviewBuilder::class)->g8labsMonthly(2026))->firstWhere('mese', 5);
    expect($maggio['costi'])->toBe(0.0); // 100 − 100
});

it('tracks real cash flow and flags unreconciled outflows as non attribuite', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    // Entrata.
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-07-05', 'amount' => 1000, 'description' => 'incasso', 'dedup_hash' => 'c1']);
    // Uscita riconciliata a un costo -> NON è "non attribuita".
    $txPaid = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-07-10', 'amount' => -200, 'description' => 'pagata', 'dedup_hash' => 'c2']);
    $costo = Costo::create(['date' => '2026-07-10', 'description' => 'Servizio', 'amount' => 200, 'vat_amount' => 0]);
    app(App\Services\Reconciliation\ReconciliationService::class)->attach($txPaid, $costo, 200);
    // Uscita NON riconciliata -> "non attribuita".
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-07-15', 'amount' => -350, 'description' => 'sconosciuta', 'dedup_hash' => 'c3']);

    $luglio = collect(app(FinancialOverviewBuilder::class)->g8labsMonthly(2026))->firstWhere('mese', 7);

    expect($luglio['entrate'])->toBe(1000.0);
    expect($luglio['uscite'])->toBe(550.0);        // 200 + 350
    expect($luglio['cassa'])->toBe(450.0);          // 1000 - 550
    expect($luglio['non_attribuite'])->toBe(350.0); // solo l'uscita non riconciliata
});

it('computes forfettario revenue and estimated taxes on the configured params', function () {
    $client = Client::create(['name' => 'Cliente Fiscozen', 'invoicing_provider' => Client::PROVIDER_FISCOZEN]);
    $inv = Invoice::create([
        'user_id' => User::factory()->admin()->create()->id, 'client_id' => $client->id,
        'number' => '1/2026', 'issue_date' => '2026-03-01', 'period_from' => '2026-03-01', 'period_to' => '2026-03-31',
        'vat_rate' => 0, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $inv->id, 'name' => 'Consulenza', 'qty' => 1, 'net_price' => 5000, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    $f = app(FinancialOverviewBuilder::class)->forfettario(2026);

    expect($f['incassato_anno'])->toBe(5000.0);
    // coeff 0.67, inps 0.2607, imposta 0.15 (dal config)
    $reddito = round(5000 * 0.67, 2);
    $inps = round($reddito * 0.2607, 2);
    $imposta = round(max(0, $reddito - $inps) * 0.15, 2);
    expect($f['reddito_lordo'])->toBe($reddito);
    expect($f['inps'])->toBe($inps);
    expect($f['imposta'])->toBe($imposta);
    expect($f['tasse_totali'])->toBe(round($inps + $imposta, 2));
});

it('reports snapshot liquidity, credits and debts', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 1000]);
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-01', 'amount' => 500, 'description' => 'x', 'dedup_hash' => 's1']);

    // Credito: fattura FiC emessa non pagata (imponibile 200, IVA 0 -> total 200).
    ficInvoice(200, 0, '2026-06-01');

    // Debito: passiva non pagata 122.
    $supplier = Supplier::create(['name' => 'Forn']);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'D-1', 'document_date' => '2026-06-01',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122, 'category' => 'X', 'payment_status' => 'not_paid',
    ]);

    $snap = app(FinancialOverviewBuilder::class)->g8labsSnapshot();
    expect($snap['liquidita'])->toBe(1500.0);
    expect($snap['crediti'])->toBe(200.0);
    expect($snap['debiti'])->toBe(122.0);
});

it('renders the dashboard for an admin and hides it from non-admins', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard-finanziaria')->assertOk()->assertSee('Dashboard finanziaria');

    $this->actingAs(User::factory()->create(['role' => 'member']))
        ->get('/dashboard-finanziaria')->assertForbidden();
});
