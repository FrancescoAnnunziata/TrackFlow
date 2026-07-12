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

it('keeps compensation taxes in the margin but treats VAT payments as a separate voice', function () {
    // F24 del compenso (ritenuta+contributi): costo vero, entra nel margine.
    Costo::create(['date' => '2026-02-18', 'description' => 'F24 compenso', 'category' => Costo::CATEGORY_TAXES, 'amount' => 500, 'vat_amount' => 0]);
    // F24 liquidazione IVA: imposta di giro, fuori dal margine, in voce separata.
    Costo::create(['date' => '2026-02-20', 'description' => 'F24 IVA', 'category' => Costo::CATEGORY_VAT, 'amount' => 2000, 'vat_amount' => 0]);

    $feb = collect(app(FinancialOverviewBuilder::class)->g8labsMonthly(2026))->firstWhere('mese', 2);

    expect($feb['costi'])->toBe(500.0);        // solo il compenso
    expect($feb['margine'])->toBe(-500.0);     // ricavi 0 − costi 500
    expect($feb['iva_versata'])->toBe(2000.0); // l'IVA in voce separata
    expect($feb['iva_credito'])->toBe(0.0);    // l'F24 IVA non è IVA a credito
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

it('detects inter-account transfers and excludes them from cash flow and non attribuite', function () {
    $a = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $b = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid', 'opening_balance' => 0]);

    // Giroconto: -5000 da InBank, +5000 su Vivid a 1 giorno -> non è cassa operativa.
    BankTransaction::create(['bank_account_id' => $a->id, 'booked_at' => '2026-04-10', 'amount' => -5000, 'description' => 'bonifico a Vivid', 'dedup_hash' => 'g1']);
    BankTransaction::create(['bank_account_id' => $b->id, 'booked_at' => '2026-04-11', 'amount' => 5000, 'description' => 'da InBank', 'dedup_hash' => 'g2']);
    // Uscita vera non riconciliata.
    BankTransaction::create(['bank_account_id' => $a->id, 'booked_at' => '2026-04-15', 'amount' => -300, 'description' => 'spesa', 'dedup_hash' => 'g3']);

    // Il comando marca la coppia come giroconto (persistente).
    $this->artisan('finance:detect-transfers')->assertSuccessful();
    expect(BankTransaction::whereNotNull('transfer_pair_id')->count())->toBe(2);

    $aprile = collect(app(FinancialOverviewBuilder::class)->g8labsMonthly(2026))->firstWhere('mese', 4);

    expect($aprile['giroconti'])->toBe(5000.0);
    expect($aprile['entrate'])->toBe(0.0);          // il +5000 è un giroconto, escluso
    expect($aprile['uscite'])->toBe(300.0);         // solo la spesa vera
    expect($aprile['non_attribuite'])->toBe(300.0); // il -5000 è escluso
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
