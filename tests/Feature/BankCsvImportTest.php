<?php

use App\Models\BankAccount;
use App\Services\Import\BankCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports signed amounts (Vivid style) with correct direction', function () {
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid', 'opening_balance' => 1000]);

    $rows = [
        ['Booking Date', 'Amount', 'Description', 'Reference'],
        ['2026-06-01', '-49.90', 'Software', 'R1'],
        ['2026-06-03', '1220.00', 'Incasso', 'R2'],
    ];

    $options = [
        'decimal' => '.', 'thousands' => ',', 'date_format' => 'Y-m-d', 'amount_mode' => 'signed',
        'columns' => ['booked_at' => 'Booking Date', 'amount' => 'Amount', 'description' => 'Description', 'reference' => 'Reference'],
    ];

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($result['imported'])->toBe(2);
    expect($account->transactions()->entrate()->count())->toBe(1);
    expect($account->transactions()->uscite()->count())->toBe(1);
    expect($account->currentBalance())->toBe(2170.10);
});

it('normalizes Dare/Avere columns (InBank style) into a signed amount', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 500]);

    $rows = [
        ['Data contabile', 'Dare', 'Avere', 'Descrizione'],
        ['01/06/2026', '100,50', '', 'Commissioni'],
        ['05/06/2026', '', '2.440,00', 'Bonifico'],
    ];

    $options = [
        'decimal' => ',', 'thousands' => '.', 'date_format' => 'd/m/Y', 'amount_mode' => 'dare_avere',
        'columns' => ['booked_at' => 'Data contabile', 'dare' => 'Dare', 'avere' => 'Avere', 'description' => 'Descrizione'],
    ];

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($result['imported'])->toBe(2);
    $uscita = $account->transactions()->uscite()->first();
    $entrata = $account->transactions()->entrate()->first();
    expect((float) $uscita->amount)->toBe(-100.50);
    expect((float) $entrata->amount)->toBe(2440.00);
    expect($account->currentBalance())->toBe(2839.50);
});

it('keeps legitimately identical rows without a reference, but stays idempotent on re-import', function () {
    // InBank non ha un ID transazione: due commissioni identiche lo stesso
    // giorno sono movimenti distinti, non un duplicato.
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $rows = [
        ['Data contabile', 'Dare', 'Avere', 'Descrizione'],
        ['29/08/2025', '0,50', '', 'Commissioni bonifico'],
        ['29/08/2025', '0,50', '', 'Commissioni bonifico'],
    ];
    $options = [
        'decimal' => ',', 'thousands' => '.', 'date_format' => 'd/m/Y', 'amount_mode' => 'dare_avere',
        'columns' => ['booked_at' => 'Data contabile', 'dare' => 'Dare', 'avere' => 'Avere', 'description' => 'Descrizione'],
    ];

    $first = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);
    expect($first['imported'])->toBe(2);
    expect($account->transactions()->count())->toBe(2);

    $second = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);
    expect($second['imported'])->toBe(0);
    expect($second['duplicates'])->toBe(2);
    expect($account->transactions()->count())->toBe(2);
});

it('deduplicates on re-import', function () {
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $rows = [
        ['Booking Date', 'Amount', 'Description', 'Reference'],
        ['2026-06-01', '-49.90', 'Software', 'R1'],
    ];
    $options = [
        'decimal' => '.', 'thousands' => ',', 'date_format' => 'Y-m-d', 'amount_mode' => 'signed',
        'columns' => ['booked_at' => 'Booking Date', 'amount' => 'Amount', 'description' => 'Description', 'reference' => 'Reference'],
    ];

    app(BankCsvImporter::class)->importRows($rows, $account->id, $options);
    $second = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($second['imported'])->toBe(0);
    expect($second['duplicates'])->toBe(1);
    expect($account->transactions()->count())->toBe(1);
});
