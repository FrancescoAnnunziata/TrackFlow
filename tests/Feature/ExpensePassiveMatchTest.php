<?php

use App\Models\Expense;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Billing\ExpensePassiveMatcher;
use App\Services\Reporting\RegistroAcquistiBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function passiveInvoice(array $attributes): PassiveInvoice
{
    return PassiveInvoice::create(array_merge([
        'number' => 'FP', 'document_date' => '2026-06-15',
        'amount_net' => 0, 'amount_vat' => 0, 'amount_gross' => 0,
        'payment_status' => 'not_paid',
    ], $attributes));
}

it('auto-links an expense to the exact passive invoice and inherits conto and supplier', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Trenitalia S.p.A.']);
    $passive = passiveInvoice([
        'supplier_id' => $supplier->id, 'number' => 'FP-1', 'document_date' => '2026-06-30',
        'amount_net' => 90, 'amount_vat' => 9, 'amount_gross' => 99, 'category' => 'Trasferte',
    ]);
    $expense = Expense::create(['user_id' => $user->id, 'date' => '2026-06-30', 'amount' => 99]);

    $linked = app(ExpensePassiveMatcher::class)->autoLinkExact();

    expect($linked)->toBe(1);
    $expense->refresh();
    expect($expense->passive_invoice_id)->toBe($passive->id);
    expect($expense->supplier_id)->toBe($supplier->id);
    expect($expense->conto)->toBe('Trasferte');
});

it('does not auto-link when two passive invoices match the same date and amount', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Telepass S.p.A.']);
    passiveInvoice(['supplier_id' => $supplier->id, 'number' => 'A', 'document_date' => '2026-06-23', 'amount_gross' => 2.60, 'category' => 'Trasferte']);
    passiveInvoice(['supplier_id' => $supplier->id, 'number' => 'B', 'document_date' => '2026-06-23', 'amount_gross' => 2.60, 'category' => 'Trasferte']);
    $expense = Expense::create(['user_id' => $user->id, 'date' => '2026-06-23', 'amount' => 2.60]);

    expect(app(ExpensePassiveMatcher::class)->autoLinkExact())->toBe(0);
    expect($expense->fresh()->passive_invoice_id)->toBeNull();
});

it('uses the passive VAT split, excludes the linked passive and groups by normalized conto', function () {
    $user = User::factory()->admin()->create();
    $supplier = Supplier::create(['name' => 'Unieuro']);

    // Passiva collegata a una spesa: split IVA sulla riga della spesa, non riga a sé.
    $linkedPassive = passiveInvoice([
        'supplier_id' => $supplier->id, 'number' => 'FP-L', 'document_date' => '2026-06-10',
        'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'category' => 'Acquisto materiale e macchinari',
    ]);
    $expense = Expense::create([
        'user_id' => $user->id, 'date' => '2026-06-10', 'amount' => 122,
        'supplier_id' => $supplier->id, 'passive_invoice_id' => $linkedPassive->id,
        'conto' => 'Acquisto materiale e macchinari',
    ]);

    // Passiva standalone nello stesso conto (categoria FiC con "e").
    passiveInvoice([
        'supplier_id' => $supplier->id, 'number' => 'FP-S', 'document_date' => '2026-06-12',
        'amount_net' => 41, 'amount_vat' => 9, 'amount_gross' => 50,
        'category' => 'Acquisto materiale e macchinari',
    ]);

    $table = app(RegistroAcquistiBuilder::class)->build(
        Carbon\Carbon::parse('2026-06-01')->startOfDay(),
        Carbon\Carbon::parse('2026-06-30')->endOfDay(),
    );
    $data = collect($table['rows'])->where('kind', 'data')->values();

    // Solo 2 righe: la spesa e la passiva standalone (la collegata è esclusa).
    expect($data)->toHaveCount(2);
    expect($data->contains(fn ($r): bool => $r['cells'][0] === 'Fattura passiva' && $r['cells'][9] === 'FP-L'))->toBeFalse();

    // La riga spesa riporta lo split IVA della passiva collegata.
    $expenseRow = $data->firstWhere(fn ($r): bool => $r['cells'][0] === 'Spesa');
    expect($expenseRow['cells'][5])->toBe(100.0);
    expect($expenseRow['cells'][6])->toBe(22.0);

    // Un solo gruppo/conto (label normalizzata) col subtotale = 122 + 50.
    $subtotals = collect($table['rows'])->where('kind', 'subtotal')->values();
    expect($subtotals)->toHaveCount(1);
    expect($subtotals[0]['cells'][3])->toBe('Subtotale Acquisto materiale e macchinari');
    expect($subtotals[0]['cells'][7])->toBe(172.0);
});
