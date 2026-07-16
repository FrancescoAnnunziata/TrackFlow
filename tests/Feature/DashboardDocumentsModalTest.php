<?php

use App\Filament\Pages\DashboardFinanziaria;
use App\Models\Client;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reporting\FinancialOverviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('opens the documents modal for ricavi and costi matching the builder', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    // --- Ricavi: fattura attiva FiC di gennaio + una bozza (esclusa). ---
    $client = Client::create(['name' => 'Acme', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $fattura = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '1/2026',
        'issue_date' => '2026-01-10', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31',
        'vat_rate' => 0, 'status' => 'sent', 'type' => Invoice::TYPE_INVOICE,
    ]);
    InvoiceItem::create(['invoice_id' => $fattura->id, 'name' => 'Servizi', 'qty' => 1, 'net_price' => 1000, 'vat_kind' => InvoiceItem::VAT_STANDARD]);
    $bozza = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'BOZZA',
        'issue_date' => '2026-01-15', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31',
        'vat_rate' => 0, 'status' => 'draft', 'type' => Invoice::TYPE_INVOICE,
    ]);
    InvoiceItem::create(['invoice_id' => $bozza->id, 'name' => 'X', 'qty' => 1, 'net_price' => 999, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    // --- Costi: passiva + costo + spesa; IVA e spesa già su passiva escluse. ---
    $supplier = Supplier::create(['name' => 'Fornitore']);
    $passiva = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'type' => 'expense', 'document_date' => '2026-01-05',
        'amount_net' => 200, 'amount_vat' => 44, 'amount_gross' => 244, 'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $costo = Costo::create(['date' => '2026-01-08', 'description' => 'Cancelleria', 'category' => 'Ufficio', 'amount' => 50, 'vat_amount' => 0]);
    $ivaCosto = Costo::create(['date' => '2026-01-20', 'description' => 'F24 IVA', 'category' => Costo::CATEGORY_VAT, 'amount' => 300, 'vat_amount' => 0]);
    $spesa = Expense::create(['user_id' => $user->id, 'date' => '2026-01-09', 'amount' => 30, 'notes' => 'Taxi']);
    $spesaSuPassiva = Expense::create(['user_id' => $user->id, 'date' => '2026-01-09', 'amount' => 99, 'notes' => 'Già su passiva', 'passive_invoice_id' => $passiva->id]);

    // L'azione si monta con gli argomenti della cella cliccata.
    Livewire::test(DashboardFinanziaria::class)
        ->mountAction('documenti', ['tipo' => 'ricavi', 'mese' => 1, 'anno' => 2026])
        ->assertActionMounted('documenti');

    $page = new DashboardFinanziaria;

    // Ricavi gennaio: la fattura sì, la bozza no.
    $ricavi = $page->documenti('ricavi', 1, 2026);
    expect($ricavi->pluck('numero'))->toContain('1/2026');
    expect($ricavi->pluck('numero'))->not->toContain('BOZZA');

    // Costi gennaio: passiva + costo + spesa; IVA e spesa-su-passiva escluse.
    $costi = $page->documenti('costi', 1, 2026);
    expect($costi->pluck('numero'))->toContain('FP-1', 'Cancelleria', 'Taxi');
    expect($costi->pluck('numero'))->not->toContain('F24 IVA', 'Già su passiva');

    // Il totale della modale coincide con la cella del builder.
    $mensile = collect(app(FinancialOverviewBuilder::class)->g8labsMonthly(2026))->firstWhere('mese', 1);
    expect(round($ricavi->sum('importo'), 2))->toBe($mensile['ricavi']);
    expect(round($costi->sum('importo'), 2))->toBe($mensile['costi']);
});
