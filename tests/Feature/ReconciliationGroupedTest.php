<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Services\Reconciliation\GroupedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function passive(Supplier $s, string $date, float $gross, string $n = 'FP'): PassiveInvoice
{
    return PassiveInvoice::create([
        'supplier_id' => $s->id, 'number' => $n, 'document_date' => $date,
        'amount_net' => $gross, 'amount_vat' => 0, 'amount_gross' => $gross,
        'payment_status' => 'not_paid',
    ]);
}

it('finds the unique subset summing to a target and rejects ambiguity', function () {
    $svc = app(GroupedMatchService::class);

    expect($svc->uniqueSubset([90.27, 6.30, 50.00], 96.57))->toBe([0, 1]);
    expect($svc->uniqueSubset([5.00, 5.00, 3.00], 8.00))->toBeNull(); // due 5+3 ambigui
    expect($svc->uniqueSubset([10.0, 20.0], 99.0))->toBeNull();       // nessuno
});

it('reconciles a split payment: one invoice paid by two movements', function () {
    $supplier = Supplier::create(['name' => 'Minerva sas']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $invoice = passive($supplier, '2025-12-03', 96.57);

    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2025-12-04', 'amount' => -90.27, 'description' => 'POS MINERVA SAS', 'dedup_hash' => 'm1']);
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2025-12-05', 'amount' => -6.30, 'description' => 'POS MINERVA SAS', 'dedup_hash' => 'm2']);

    $n = app(GroupedMatchService::class)->matchSplit();

    expect($n)->toBe(1);
    expect($invoice->fresh()->payment_status)->toBe('paid');
});

it('reconciles a grouped payment: one movement paying two invoices', function () {
    $supplier = Supplier::create(['name' => 'Telepass SpA']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    $a = passive($supplier, '2026-06-20', 5.00, 'T-A');
    $b = passive($supplier, '2026-06-22', 3.13, 'T-B');

    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-23', 'amount' => -8.13, 'description' => 'ADDEBITO SDD TELEPASS SPA', 'dedup_hash' => 't1']);

    $n = app(GroupedMatchService::class)->matchGrouped();

    expect($n)->toBe(1);
    expect($a->fresh()->payment_status)->toBe('paid');
    expect($b->fresh()->payment_status)->toBe('paid');
});

it('does not group when the supplier name is absent from the movement', function () {
    $supplier = Supplier::create(['name' => 'Telepass SpA']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);
    passive($supplier, '2026-06-20', 5.00, 'T-A');
    passive($supplier, '2026-06-22', 3.13, 'T-B');

    // Descrizione senza il nome del fornitore → nessun match (sicurezza).
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-23', 'amount' => -8.13, 'description' => 'ADDEBITO SDD GENERICO', 'dedup_hash' => 't2']);

    expect(app(GroupedMatchService::class)->matchGrouped())->toBe(0);
});
