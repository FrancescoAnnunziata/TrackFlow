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

it('skips document preamble rows before the header (Directa style)', function () {
    // Gli export Directa antepongono righe descrittive prima dell'intestazione
    // vera; l'importo è già con segno e il decimale è il punto.
    $account = BankAccount::create(['name' => 'Directa', 'bank_key' => 'directa']);

    $rows = [
        ['Conto : P2284 G8LABS S.R.L. UNIPERSONALE'],
        ['Data estrazione : 8-7-2026 9:56:19'],
        [],
        ['Data operazione', 'Data valuta', 'Tipo operazione', 'Descrizione', 'Importo euro'],
        ['14-01-2026', '14-01-2026', 'Conferimento con bonifico', '', '2000'],
        ['15-01-2026', '19-01-2026', 'Acquisto', 'Vanguard Ftse All-World Ucits', '-1953.38'],
        ['10-04-2026', '31-03-2026', 'Bollo portafoglio titoli', '', '-0.8'],
    ];

    $options = config('banks.presets.directa');

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($result['imported'])->toBe(3);
    expect($account->transactions()->entrate()->count())->toBe(1);
    expect($account->transactions()->uscite()->count())->toBe(2);
    $bollo = $account->transactions()->where('description', 'Bollo portafoglio titoli')->first();
    expect((float) $bollo->amount)->toBe(-0.8);
    expect($bollo->counterparty)->toBeNull();
    $etf = $account->transactions()->where('description', 'Acquisto')->first();
    expect($etf->counterparty)->toBe('Vanguard Ftse All-World Ucits');
});

it('skips InBank balance summary rows but keeps a "Saldo Fattura" transfer', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    $rows = [
        ['Data contabile', 'Dare', 'Avere', 'Descrizione'],
        ['05/06/2026', '', '2.440,00', 'Bonifico'],
        // Bonifico con causale "Saldo Fattura...": è un movimento vero, va tenuto.
        ['06/06/2026', '', '1.000,00', 'Saldo Fattura 14 SOCIETA BOCCIOFILA'],
        // Righe di riepilogo in coda all'estratto: NON sono movimenti.
        ['06/07/2026', '', '282,29', 'Saldo contabile'],
        ['06/07/2026', '', '282,29', 'Saldo liquido'],
        ['06/07/2026', '', '0,00', 'Saldo SBF per conti unici al'],
        ['06/07/2026', '', '256,02', 'Disponibilità al'],
    ];
    $options = [
        'decimal' => ',', 'thousands' => '.', 'date_format' => 'd/m/Y', 'amount_mode' => 'dare_avere',
        'columns' => ['booked_at' => 'Data contabile', 'dare' => 'Dare', 'avere' => 'Avere', 'description' => 'Descrizione'],
    ];

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(4);
    expect($account->transactions()->count())->toBe(2);
    expect($account->transactions()->where('description', 'like', 'Saldo Fattura%')->exists())->toBeTrue();
    expect($account->transactions()->where('description', 'like', 'Disponibilit%')->exists())->toBeFalse();
});

it('skips a Vivid authorization row (empty description) when a described charge twin exists', function () {
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid', 'opening_balance' => 0]);

    $rows = [
        ['Booking Date', 'Amount', 'Description'],
        // Autorizzazione: descrizione vuota, giorno prima.
        ['2026-04-15', '-64.00', ''],
        // Addebito vero col merchant, giorno dopo, stesso importo: si tiene questo.
        ['2026-04-16', '-64.00', 'TRATTORIA TRE STELLE'],
        // Due pasti veri identici (entrambi descritti): NON vanno uniti.
        ['2026-05-01', '-31.00', 'RISTORANTE GIAPPONESE'],
        ['2026-05-02', '-31.00', 'RISTORANTE GIAPPONESE'],
    ];
    $options = [
        'decimal' => '.', 'thousands' => ',', 'date_format' => 'Y-m-d', 'amount_mode' => 'signed',
        'columns' => ['booked_at' => 'Booking Date', 'amount' => 'Amount', 'description' => 'Description'],
    ];

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    expect($result['imported'])->toBe(3);   // 1 auth saltata, 1 addebito + 2 pasti veri
    expect($result['skipped'])->toBe(1);
    expect($account->transactions()->whereNull('description')->count())->toBe(0);
    expect($account->transactions()->where('description', 'like', 'RISTORANTE%')->count())->toBe(2);
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

it('accepts either Vivid header for the date column, from the same preset', function () {
    $preset = config('banks.presets.vivid');
    $options = [
        'decimal' => $preset['decimal'], 'thousands' => $preset['thousands'],
        'date_format' => $preset['date_format'], 'amount_mode' => $preset['amount_mode'],
        'columns' => $preset['columns'],
    ];

    // Estratto del conto IBAN: "Completed date", date con il punto.
    $iban = BankAccount::create(['name' => 'Vivid IBAN', 'bank_key' => 'vivid']);
    $result = app(BankCsvImporter::class)->importRows([
        ['Completed date', 'Counterparty name', 'Reference', 'Payment amount', 'Payment currency'],
        ['02.06.2026', 'BAR PASTICCERIA G, AGNADELLO, IT', 'BAR PASTICCERIA G, AGNADELLO, IT', '-21.8', 'EUR'],
        ['10.06.2026', 'G8LABS S.R.L. UNIPERSONALE', 'Trasferimento fondi', '3000', 'EUR'],
    ], $iban->id, $options);

    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(0);
    expect($iban->transactions()->min('booked_at'))->toBe('2026-06-02');
    expect($iban->transactions()->uscite()->sum('amount'))->toBe('-21.80');

    // Export carta: "Transaction date", date col trattino. Stesso preset.
    $card = BankAccount::create(['name' => 'Vivid carta', 'bank_key' => 'vivid']);
    $result = app(BankCsvImporter::class)->importRows([
        ['Transaction date', 'Counterparty name', 'Reference', 'Payment amount', 'Internal operation id'],
        ['02-06-2026', 'MC DONALD S, VOGHERA, IT', 'MC DONALD S, VOGHERA, IT', '-51.1', 'op-1'],
    ], $card->id, $options);

    expect($result['imported'])->toBe(1);
    expect($card->transactions()->first()->booked_at->toDateString())->toBe('2026-06-02');
    expect($card->transactions()->first()->bank_reference)->toBe('op-1');
});

it('reports a clear error when no mapped header matches the date column', function () {
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);

    expect(fn () => app(BankCsvImporter::class)->importRows([
        ['Data strana', 'Payment amount'],
        ['02.06.2026', '-10'],
    ], $account->id, [
        'date_format' => 'd-m-Y', 'amount_mode' => 'signed',
        'columns' => ['booked_at' => 'Completed date|Transaction date', 'amount' => 'Payment amount'],
    ]))->toThrow(RuntimeException::class, 'Colonna Data non trovata');
});

it('skips InBank opening/closing balance rows', function () {
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    $rows = [
        ['Data contabile', 'Dare', 'Avere', 'Descrizione'],
        ['01/06/2026', '', '801,39', 'Saldo iniziale'],
        ['06/07/2026', '7000,00', '', 'BONIFICO SCT INSTANT INBANK A Fav G8LABS'],
        ['31/07/2026', '', '1234,00', 'Saldo finale'],
    ];
    $options = [
        'decimal' => ',', 'thousands' => '.', 'date_format' => 'd/m/Y', 'amount_mode' => 'dare_avere',
        'columns' => ['booked_at' => 'Data contabile', 'dare' => 'Dare', 'avere' => 'Avere', 'description' => 'Descrizione'],
    ];

    $result = app(BankCsvImporter::class)->importRows($rows, $account->id, $options);

    // Solo il bonifico entra; le due righe di saldo sono scartate.
    expect($result['imported'])->toBe(1);
    expect($account->transactions()->where('description', 'like', 'Saldo%')->count())->toBe(0);
});
