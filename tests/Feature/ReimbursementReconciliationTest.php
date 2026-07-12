<?php

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('closes the linked passive invoices when the reimbursement bonifico is reconciled', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Trenitalia S.p.A.']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    // Nota rimborso €199: fattura passiva anticipata €99 + costo km €100.
    $reimb = Reimbursement::create([
        'user_id' => $user->id, 'type' => ReimbursementType::Travel,
        'date' => '2025-09-30', 'amount' => 199.00, 'notes' => 'Rimborso settembre',
    ]);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'document_date' => '2025-09-18',
        'amount_net' => 90, 'amount_vat' => 9, 'amount_gross' => 99,
        'category' => 'Trasferte', 'payment_status' => 'not_paid', 'reimbursement_id' => $reimb->id,
    ]);
    Costo::create([
        'date' => '2025-09-30', 'description' => 'Rimborso km settembre', 'category' => 'Trasferte',
        'amount' => 100, 'vat_amount' => 0, 'reimbursement_id' => $reimb->id,
    ]);

    // Bonifico di rimborso a Giorgio.
    $bonifico = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2025-10-05', 'amount' => -199,
        'description' => 'BONIFICO rimborso spese settembre', 'dedup_hash' => 'r1',
    ]);

    app(ReconciliationService::class)->attach($bonifico, $reimb, 199, 'manual');

    expect($reimb->fresh()->status)->toBe(ReimbursementStatus::Paid);
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
    expect($bonifico->fresh()->reconciled)->toBeTrue();
    expect($bonifico->fresh()->unreconciledAmount())->toBe(0.0);
});

it('reverts the linked passives to not paid when the reimbursement is unreconciled', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Unieuro']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $reimb = Reimbursement::create(['user_id' => $user->id, 'type' => ReimbursementType::Travel, 'date' => '2025-08-31', 'amount' => 99]);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-2', 'document_date' => '2025-08-20',
        'amount_net' => 99, 'amount_vat' => 0, 'amount_gross' => 99,
        'category' => 'X', 'payment_status' => 'not_paid', 'reimbursement_id' => $reimb->id,
    ]);
    $tx = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2025-09-01', 'amount' => -99, 'description' => 'x', 'dedup_hash' => 'r2']);
    $rec = app(ReconciliationService::class)->attach($tx, $reimb, 99, 'manual');
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);

    app(ReconciliationService::class)->detach($rec);

    expect($reimb->fresh()->status)->toBe(ReimbursementStatus::Pending);
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_NOT_PAID);
});

it('registers a payslip as a cost and reconciles the salary bonifico', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $bonifico = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-02-23', 'amount' => -1500,
        'description' => 'BONIFICO retribuzione gennaio', 'dedup_hash' => 'p1',
    ]);

    $spec = json_encode([
        'date' => '2026-01-31', 'amount' => 1500, 'conto' => 'Collaboratori',
        'descrizione' => 'Compenso amministratore Gennaio 2026', 'bonifico_tx' => $bonifico->id,
    ]);
    $this->artisan('finance:register-payslip', ['--spec' => $spec])->assertSuccessful();

    $costo = App\Models\Costo::where('category', 'Collaboratori')->where('amount', 1500)->first();
    expect($costo)->not->toBeNull();
    expect($bonifico->fresh()->reconciled)->toBeTrue();
    expect($bonifico->fresh()->unreconciledAmount())->toBe(0.0);

    // Idempotente: una seconda esecuzione non duplica.
    $this->artisan('finance:register-payslip', ['--spec' => $spec])->assertSuccessful();
    expect(App\Models\Costo::where('category', 'Collaboratori')->where('amount', 1500)->count())->toBe(1);
});

it('links the passive invoices selected in the create form', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Tekworld']);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-9', 'document_date' => '2026-01-12',
        'amount_net' => 400, 'amount_vat' => 5.31, 'amount_gross' => 405.31,
        'category' => 'X', 'payment_status' => 'not_paid',
    ]);

    $this->actingAs($user);
    Livewire\Livewire::test(App\Filament\Resources\Reimbursements\Pages\CreateReimbursement::class)
        ->fillForm([
            'type' => 'trasferta', 'date' => '2026-01-31', 'amount' => 405.31,
            'status' => 'pending', 'passiveInvoices' => [$passive->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($passive->fresh()->reimbursement_id)->not->toBeNull();
});

it('excludes reimbursement-linked passive invoices from bank match candidates', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Trenitalia S.p.A.']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $reimb = Reimbursement::create(['user_id' => $user->id, 'type' => ReimbursementType::Travel, 'date' => '2025-09-30', 'amount' => 99]);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'FP-3', 'document_date' => '2025-09-18',
        'amount_net' => 90, 'amount_vat' => 9, 'amount_gross' => 99,
        'category' => 'Trasferte', 'payment_status' => 'not_paid', 'reimbursement_id' => $reimb->id,
    ]);

    // Un'altra uscita da €99: la passiva collegata al rimborso NON deve comparire.
    $tx = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2025-09-20', 'amount' => -99, 'description' => 'POS Trenitalia', 'dedup_hash' => 'r3']);
    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions->contains(fn (array $s): bool => $s['model'] instanceof PassiveInvoice))->toBeFalse();
});
