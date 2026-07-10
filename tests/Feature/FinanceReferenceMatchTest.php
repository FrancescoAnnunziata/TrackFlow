<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Models\Supplier;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function refPassive(string $name, string $date, float $gross): PassiveInvoice
{
    $supplier = Supplier::firstOrCreate(['name' => $name]);

    return PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'X', 'document_date' => $date,
        'amount_net' => $gross, 'amount_vat' => 0, 'amount_gross' => $gross,
        'category' => 'X', 'payment_status' => 'not_paid',
    ]);
}

it('links an unreconciled invoice to the payment that cites its date', function () {
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $inv = refPassive('FEDERICO MARRA', '2026-05-26', 3100);
    BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-05-28', 'amount' => -3100,
        'description' => 'Numero Fattura: FPR 3/26 26/05/2026', 'counterparty' => 'FEDERICO MARRA', 'dedup_hash' => 'r1',
    ]);

    $this->artisan('finance:match-references')->assertSuccessful();

    expect($inv->fresh()->payment_status)->toBe('paid');
    expect($inv->reconciliations()->count())->toBe(1);
});

it('corrects an auto-match on the wrong date to the payment that cites the invoice', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $inv = refPassive('RISTORANTE YANG', '2025-11-10', 31);

    // Auto-match sbagliato: stesso importo ma "Giorno" diverso (31/10).
    $wrong = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2025-11-05', 'amount' => -31,
        'description' => 'POS Giorno 31/10/2025 C/O RISTORANTE YANG', 'dedup_hash' => 'w1',
    ]);
    app(ReconciliationService::class)->attach($wrong, $inv, 31, Reconciliation::BY_AUTO);

    // Pagamento che cita la data giusta (10/11).
    $right = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2025-11-12', 'amount' => -31,
        'description' => 'POS Giorno 10/11/2025 C/O RISTORANTE YANG', 'dedup_hash' => 'r2',
    ]);

    $this->artisan('finance:match-references')->assertSuccessful();

    // La riconciliazione si sposta sul movimento giusto; quello sbagliato si libera.
    expect($inv->reconciliations()->pluck('bank_transaction_id')->all())->toBe([$right->id]);
    expect($wrong->fresh()->reconciled)->toBeFalse();
});

it('does not touch invoices reconciled manually', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $inv = refPassive('FORNITORE X', '2026-05-26', 100);
    $manual = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-05-20', 'amount' => -100,
        'description' => 'pagamento generico', 'dedup_hash' => 'm1',
    ]);
    app(ReconciliationService::class)->attach($manual, $inv, 100, Reconciliation::BY_MANUAL);

    // Movimento che cita la data giusta, ma non deve scavalcare il manuale.
    BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-05-27', 'amount' => -100,
        'description' => 'Numero Fattura X 26/05/2026', 'counterparty' => 'FORNITORE X', 'dedup_hash' => 'r3',
    ]);

    $this->artisan('finance:match-references')->assertSuccessful();

    expect($inv->reconciliations()->pluck('bank_transaction_id')->all())->toBe([$manual->id]);
});
