<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('suggests the passive invoice and not the linked expense for an outflow', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Trenitalia S.p.A.']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'document_date' => '2026-06-30',
        'amount_net' => 90, 'amount_vat' => 9, 'amount_gross' => 99, 'category' => 'Trasferte',
        'payment_status' => 'not_paid',
    ]);
    // Spesa collegata alla passiva: NON deve comparire come candidato a sé.
    $expense = Expense::create([
        'user_id' => $user->id, 'date' => '2026-06-30', 'amount' => 99,
        'supplier_id' => $supplier->id, 'passive_invoice_id' => $passive->id,
    ]);

    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-30',
        'amount' => -99, 'description' => 'Trenitalia', 'dedup_hash' => 'x1',
    ]);

    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof PassiveInvoice && $s['model']->is($passive)))->toBeTrue();
    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Expense && $s['model']->is($expense)))->toBeFalse();
});

it('non propone la spesa nemmeno quando non ha una fattura passiva dietro', function () {
    $user = User::factory()->admin()->create();
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $expense = Expense::create(['user_id' => $user->id, 'date' => '2026-06-15', 'amount' => 50, 'conto' => 'Ristorazione']);

    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15',
        'amount' => -50, 'description' => 'POS ristorante', 'dedup_hash' => 'x2',
    ]);

    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Expense && $s['model']->is($expense)))->toBeFalse();
});

it('non propone i costi che appartengono a un rimborso spese', function () {
    // Il bonifico che li salda si aggancia al documento Rimborso spese: proporre
    // anche le singole voci significherebbe contare lo stesso costo due volte.
    $user = User::factory()->admin()->create();
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $rimborso = Reimbursement::create(['user_id' => $user->id, 'date' => '2026-06-30', 'notes' => 'Giugno', 'amount' => 120]);

    $suo = Costo::create(['date' => '2026-06-10', 'description' => 'Rimborso chilometrico', 'category' => 'Trasferte', 'amount' => 120, 'reimbursement_id' => $rimborso->id]);
    $libero = Costo::create(['date' => '2026-06-10', 'description' => 'Commissione bonifico', 'category' => 'Commissioni bancarie', 'amount' => 120]);

    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-12',
        'amount' => -120, 'description' => 'Uscita da riconciliare', 'dedup_hash' => 'x3',
    ]);

    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Costo && $s['model']->is($suo)))->toBeFalse()
        ->and($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Costo && $s['model']->is($libero)))->toBeTrue();
});

it('fuzzy-matches a foreign-currency outflow to the passive by name and near amount', function () {
    $supplier = Supplier::create(['name' => 'Amazon Web Services EMEA SARL']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    // Fattura 48,31 in USD; l'addebito in EUR è 48,43 due giorni dopo.
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'AWS-1', 'document_date' => '2026-03-01',
        'amount_net' => 48.31, 'amount_vat' => 0, 'amount_gross' => 48.31,
        'category' => 'Software e abbonamenti cloud', 'payment_status' => 'not_paid',
    ]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-03',
        'amount' => -48.43, 'description' => 'PAGAMENTO POS aws.amazon.co AWS EMEA', 'dedup_hash' => 'aws1',
    ]);

    // Senza --fuzzy non deve agganciare (importo non esatto).
    test()->artisan('finance:auto-reconcile')->assertSuccessful();
    expect($tx->fresh()->reconciled)->toBeFalse();

    // Con --fuzzy: nome "amazon" nella descrizione + importo entro tolleranza.
    test()->artisan('finance:auto-reconcile', ['--fuzzy' => true])->assertSuccessful();

    expect($tx->fresh()->reconciled)->toBeTrue();
    // Copertura tollerante: pagato 48,43 per una fattura da 48,31 → pagata.
    expect($passive->fresh()->payment_status)->toBe('paid');
});

it('creates a costo from an outflow and reconciles it (behaviour behind the UI action)', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-10',
        'amount' => -2.50, 'description' => 'Commissione bonifico', 'dedup_hash' => 'x3',
    ]);

    // Riproduce l'azione "Segna come costo".
    $costo = Costo::create([
        'date' => $tx->booked_at, 'description' => 'Commissione bonifico',
        'category' => 'Commissioni bancarie', 'amount' => 2.50, 'vat_amount' => 0,
        'bank_transaction_id' => $tx->id,
    ]);
    app(ReconciliationService::class)->attach($tx, $costo, 2.50);

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect(Reconciliation::where('reconcilable_type', 'costo')->where('reconcilable_id', $costo->id)->exists())->toBeTrue();
    expect($tx->fresh()->unreconciledAmount())->toBe(0.0);
});
