<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
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

it('still suggests a standalone expense (no passive) for an outflow', function () {
    $user = User::factory()->admin()->create();
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $expense = Expense::create(['user_id' => $user->id, 'date' => '2026-06-15', 'amount' => 50, 'conto' => 'Ristorazione']);

    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15',
        'amount' => -50, 'description' => 'POS ristorante', 'dedup_hash' => 'x2',
    ]);

    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof Expense && $s['model']->is($expense)))->toBeTrue();
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
