<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reporting\PrimaNotaBuilder;
use App\Services\Reporting\RegistroAcquistiBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<int, array<int, mixed>> celle delle sole righe dati */
function dataCells(array $table): array
{
    return collect($table['rows'])->where('kind', 'data')->pluck('cells')->all();
}

it('reconciles an expense with a bank outflow and suggests it as a candidate', function () {
    $user = User::factory()->admin()->create();
    $account = BankAccount::create(['name' => 'Conto', 'bank_key' => 'generic', 'opening_balance' => 0]);
    $expense = Expense::create([
        'user_id' => $user->id, 'date' => '2026-06-15', 'amount' => 50,
        'conto' => 'Ristorazione',
    ]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15',
        'amount' => -50, 'description' => 'POS Ristorante', 'dedup_hash' => 'e1',
    ]);

    // La spesa è fra i candidati per l'uscita.
    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);
    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Expense && $s['model']->is($expense)))->toBeTrue();

    app(ReconciliationService::class)->attach($tx, $expense, 50);

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($expense->reconciledAmount())->toBe(50.0);

    // Riconciliata, non è più fra i candidati.
    $after = app(MatchSuggestionService::class)->suggestions($tx->fresh());
    expect($after->contains(fn (array $s): bool => $s['model'] instanceof Expense && $s['model']->is($expense)))->toBeFalse();
});

it('builds the registro acquisti with conto grouping, riaddebito and no double counting', function () {
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Cliente SpA', 'vat_number' => 'IT11111111111']);
    $supplier = Supplier::create(['name' => 'Trenitalia']);

    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '5/2026',
        'issue_date' => '2026-06-30', 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
        'vat_rate' => 22, 'status' => 'sent',
    ]);

    // Spesa con conto, fornitore e riaddebito a fattura attiva.
    $expenseA = Expense::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'supplier_id' => $supplier->id,
        'date' => '2026-06-10', 'amount' => 50, 'conto' => 'Trasferte',
    ]);
    $expenseA->invoices()->attach($invoice->id);

    // Fattura passiva collegata a una spesa: NON deve comparire come riga a sé.
    $pLinked = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'document_date' => '2026-06-12',
        'amount_net' => 30, 'amount_vat' => 0, 'amount_gross' => 30,
        'category' => 'Software e abbonamenti cloud', 'payment_status' => 'not_paid',
    ]);
    Expense::create([
        'user_id' => $user->id, 'supplier_id' => $supplier->id, 'passive_invoice_id' => $pLinked->id,
        'date' => '2026-06-12', 'amount' => 30, 'conto' => 'Software e abbonamenti cloud',
    ]);

    // Costo senza fattura.
    Costo::create([
        'date' => '2026-06-20', 'description' => 'Commissione', 'category' => 'Commissioni bancarie',
        'amount' => 10, 'vat_amount' => 0,
    ]);

    // Fattura passiva standalone (nessuna spesa la referenzia): riga a sé.
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-2', 'document_date' => '2026-06-25',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'category' => 'Software e abbonamenti cloud', 'payment_status' => 'not_paid',
    ]);

    $table = app(RegistroAcquistiBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $cells = dataCells($table);

    // 4 righe dati: 2 spese + 1 costo + 1 passiva standalone (la passiva collegata è esclusa).
    expect($cells)->toHaveCount(4);

    // La passiva collegata (FP-1) non appare come riga "Fattura passiva".
    expect(collect($cells)->contains(fn (array $c): bool => $c[0] === 'Fattura passiva' && $c[9] === 'FP-1'))->toBeFalse();
    // La standalone FP-2 sì.
    expect(collect($cells)->contains(fn (array $c): bool => $c[0] === 'Fattura passiva' && $c[9] === 'FP-2'))->toBeTrue();

    // La spesa riaddebitata riporta il numero della fattura attiva.
    $riadd = collect($cells)->firstWhere(0, 'Spesa');
    expect(collect($cells)->contains(fn (array $c): bool => $c[10] === '5/2026'))->toBeTrue();

    // Totale generale = 50 + 30 + 10 + 122 = 212 (nessun doppio conteggio).
    $total = collect($table['rows'])->firstWhere('kind', 'total');
    expect($total['cells'][7])->toBe(212.0);
});

it('shows credit notes as negative rows that reduce the registro total', function () {
    $supplier = Supplier::create(['name' => 'Amazon EU']);
    // Fattura + nota di credito dello stesso importo (reso): netto 0.
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'type' => 'expense',
        'document_date' => '2026-06-10', 'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'category' => 'Acquisto materiale e macchinari', 'payment_status' => 'not_paid',
    ]);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'NC-1', 'type' => 'passive_credit_note',
        'document_date' => '2026-06-11', 'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'category' => 'Acquisto materiale e macchinari', 'payment_status' => 'not_paid',
    ]);

    $table = app(RegistroAcquistiBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $data = collect($table['rows'])->where('kind', 'data')->values();

    $creditRow = $data->firstWhere(fn ($r): bool => $r['cells'][0] === 'Nota di credito');
    expect($creditRow)->not->toBeNull();
    expect($creditRow['cells'][7])->toBe(-122.0); // totale negativo

    // Fattura +122, nota -122 → totale generale 0.
    $total = collect($table['rows'])->firstWhere('kind', 'total');
    expect($total['cells'][7])->toBe(0.0);
});

it('references the giustificativo per origin in the registro acquisti', function () {
    $supplier = Supplier::create(['name' => 'Anthropic']);
    // Estera con PDF locale.
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'INV-8', 'type' => 'expense', 'document_date' => '2026-06-05',
        'amount_net' => 90, 'amount_vat' => 0, 'amount_gross' => 90, 'category' => 'Servizi',
        'payment_status' => 'not_paid', 'attachment' => 'passive-attachments/inv-8.pdf',
    ]);
    // FiC (PDF su Fatture in Cloud).
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'GCITD777', 'type' => 'expense', 'document_date' => '2026-06-06',
        'amount_net' => 50, 'amount_vat' => 11, 'amount_gross' => 61, 'category' => 'Servizi',
        'payment_status' => 'not_paid', 'fic_document_id' => 424242,
    ]);

    $data = collect(app(RegistroAcquistiBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    )['rows'])->where('kind', 'data')->keyBy(fn ($r) => $r['cells'][9]);

    // Estera: Tipo marcato e giustificativo con link pubblico al PDF.
    expect($data['INV-8']['cells'][0])->toBe('Fattura passiva estera');
    expect($data['INV-8']['cells'][8])->toContain('passive-attachments/inv-8.pdf');

    // FiC: Tipo normale, giustificativo rimanda a Fatture in Cloud.
    expect($data['GCITD777']['cells'][0])->toBe('Fattura passiva');
    expect($data['GCITD777']['cells'][8])->toBe('Vedere fattura GCITD777 su Fatture in Cloud');
});

it('builds the prima nota with a running balance and reconciled document labels', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 100]);

    // Movimento prima del periodo: sposta il saldo di apertura di giugno a 70.
    BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-05-20',
        'amount' => -30, 'description' => 'Precedente', 'dedup_hash' => 'p0',
    ]);
    $txIn = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-05',
        'amount' => 200, 'description' => 'Bonifico', 'dedup_hash' => 'p1',
    ]);
    $txOut = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-10',
        'amount' => -50, 'description' => 'Commissione', 'dedup_hash' => 'p2',
    ]);

    $costo = Costo::create(['date' => '2026-06-10', 'description' => 'Commissione', 'amount' => 50, 'vat_amount' => 0]);
    app(ReconciliationService::class)->attach($txOut, $costo, 50);

    $table = app(PrimaNotaBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $cells = dataCells($table);

    expect($cells)->toHaveCount(2);
    // Saldo progressivo: 70 + 200 = 270, poi 270 - 50 = 220.
    expect($cells[0][6])->toBe(270.0);
    expect($cells[1][6])->toBe(220.0);
    // La riga in uscita è riconciliata e riporta solo "Costo" (niente
    // descrizione rumorosa). Senza giustificativo, nessun link.
    expect($cells[1][7])->toBe('Sì');
    expect($cells[1][8])->toBe('Costo');
    expect($cells[1][9])->toBe('');

    // Riga di saldo finale.
    $subtotal = collect($table['rows'])->firstWhere('kind', 'subtotal');
    expect($subtotal['cells'][6])->toBe(220.0);
});

it('labels a reimbursement reconciliation and links it in the prima nota', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 1000]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15',
        'amount' => -573.77, 'description' => 'BONIFICO A fav: Giorgio Giotto', 'dedup_hash' => 'r1',
    ]);
    $reimbursement = Reimbursement::create([
        'user_id' => User::factory()->create()->id, 'type' => 'trasferta', 'status' => 'paid',
        'date' => '2026-06-15', 'amount' => 573.77, 'notes' => 'Rimborsi spese agosto',
    ]);
    app(ReconciliationService::class)->attach($tx, $reimbursement, 573.77);

    $table = app(PrimaNotaBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $cells = dataCells($table);

    expect($cells[0][7])->toBe('Sì');
    expect($cells[0][8])->toBe('Rimborso spese: Rimborsi spese agosto');
    // Rimborso senza giustificativo allegato: nessun link.
    expect($cells[0][9])->toBe('');
});

it('links the giustificativo PDF when the reconciled document has one', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 500]);
    $txPdf = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-08',
        'amount' => -90, 'description' => 'Fattura estera', 'dedup_hash' => 'a1',
    ]);
    $txNoPdf = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-09',
        'amount' => -10, 'description' => 'Costo senza pdf', 'dedup_hash' => 'a2',
    ]);
    $supplier = Supplier::create(['name' => 'Anthropic']);
    $withPdf = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'INV-1', 'type' => 'expense', 'document_date' => '2026-06-08',
        'amount_net' => 90, 'amount_vat' => 0, 'amount_gross' => 90, 'payment_status' => 'not_paid',
        'attachment' => 'passive-attachments/inv-1.pdf',
    ]);
    $costo = Costo::create(['date' => '2026-06-09', 'description' => 'Costo', 'amount' => 10, 'vat_amount' => 0]);
    app(ReconciliationService::class)->attach($txPdf, $withPdf, 90);
    app(ReconciliationService::class)->attach($txNoPdf, $costo, 10);

    $cells = dataCells(app(PrimaNotaBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    ));

    // Con PDF locale: il link punta al file; senza PDF: nessun link.
    expect($cells[0][9])->toContain('passive-attachments/inv-1.pdf');
    expect($cells[1][9])->toBe('');
});

it('labels inter-account transfers as "Giroconto" in the prima nota', function () {
    $a = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $b = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid', 'opening_balance' => 0]);
    $out = BankTransaction::create(['bank_account_id' => $a->id, 'booked_at' => '2026-06-10', 'amount' => -1000, 'description' => 'bonifico', 'dedup_hash' => 't1', 'transfer_pair_id' => null]);
    $in = BankTransaction::create(['bank_account_id' => $b->id, 'booked_at' => '2026-06-10', 'amount' => 1000, 'description' => 'ricevuto', 'dedup_hash' => 't2', 'transfer_pair_id' => null]);
    $out->update(['transfer_pair_id' => $in->id]);
    $in->update(['transfer_pair_id' => $out->id]);

    $table = app(PrimaNotaBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $cells = collect($table['rows'])->where('kind', 'data')->pluck('cells');

    // La causale (indice 8) riporta "Giroconto" con conto gemello e data.
    expect($cells->every(fn ($c): bool => str_contains($c[8], 'Giroconto')))->toBeTrue();
    // L'uscita da InBank punta a Vivid, l'entrata su Vivid punta a InBank; in
    // entrambe compare la data del movimento gemello (10/06/2026).
    $causali = $cells->pluck(8);
    expect($causali->contains(fn ($c): bool => str_contains($c, '→ Vivid') && str_contains($c, '10/06/2026')))->toBeTrue();
    expect($causali->contains(fn ($c): bool => str_contains($c, '← InBank') && str_contains($c, '10/06/2026')))->toBeTrue();

    // Anche la colonna Riconciliato (indice 7) mostra "Giroconto", non "No".
    expect($cells->every(fn ($c): bool => $c[7] === 'Giroconto'))->toBeTrue();
});

it('renders the report pages for an admin and hides them from non-admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/registro-acquisti')->assertOk()->assertSee('Registro acquisti');
    $this->actingAs($admin)->get('/prima-nota')->assertOk()->assertSee('Prima nota');

    $member = User::factory()->create(['role' => 'member']);
    $this->actingAs($member)->get('/registro-acquisti')->assertForbidden();
    $this->actingAs($member)->get('/prima-nota')->assertForbidden();
});
