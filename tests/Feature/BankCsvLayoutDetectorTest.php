<?php

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\User;
use App\Services\Ai\BankCsvLayoutDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.api_key', 'sk-test');
    $this->actingAs(User::factory()->admin()->create());
});

it('riconosce un tracciato con colonne dare/avere', function () {
    $layout = app(BankCsvLayoutDetector::class)->mappaturaDa(json_encode([
        'delimiter' => ';',
        'decimal' => ',',
        'thousands' => '.',
        'date_format' => 'd/m/Y',
        'amount_mode' => 'dare_avere',
        'columns' => [
            'booked_at' => 'DATA',
            'value_date' => 'VALUTA',
            'amount' => '',
            'dare' => 'DARE',
            'avere' => 'AVERE',
            'description' => 'DESCRIZIONE_OPERAZIONE',
            'counterparty' => 'CAUSALE_ABI',
            'reference' => '',
        ],
        'note' => 'Estratto con colonne Dare/Avere',
    ]));

    expect($layout['amount_mode'])->toBe('dare_avere')
        ->and($layout['delimiter'])->toBe(';')
        ->and($layout['date_format'])->toBe('d/m/Y')
        ->and($layout['columns']['booked_at'])->toBe('DATA')
        ->and($layout['columns']['dare'])->toBe('DARE')
        ->and($layout['note'])->toBe('Estratto con colonne Dare/Avere');
});

it('produce per il file InBank la stessa configurazione del preset', function () {
    // Se il riconoscimento e il preset non coincidono su una banca che
    // conosciamo, uno dei due è sbagliato.
    $layout = app(BankCsvLayoutDetector::class)->mappaturaDa(json_encode([
        'delimiter' => ';',
        'decimal' => ',',
        'thousands' => '.',
        'date_format' => 'd/m/Y',
        'amount_mode' => 'dare_avere',
        'columns' => [
            'booked_at' => 'DATA',
            'value_date' => 'VALUTA',
            'amount' => '',
            'dare' => 'DARE',
            'avere' => 'AVERE',
            'description' => 'DESCRIZIONE_OPERAZIONE',
            'counterparty' => 'CAUSALE_ABI',
            'reference' => '',
        ],
    ]));

    $preset = config('banks.presets.inbank');

    expect($layout['delimiter'])->toBe($preset['delimiter'])
        ->and($layout['decimal'])->toBe($preset['decimal'])
        ->and($layout['date_format'])->toBe($preset['date_format'])
        ->and($layout['amount_mode'])->toBe($preset['amount_mode'])
        ->and($layout['columns'])->toEqual($preset['columns']);
});

it('azzera le colonne incoerenti con la modalità scelta', function () {
    // Il modello a volte compila sia la colonna unica sia dare/avere: lasciarle
    // entrambe nel form fa sembrare sbagliata una configurazione buona.
    $layout = app(BankCsvLayoutDetector::class)->mappaturaDa(json_encode([
        'delimiter' => ',',
        'decimal' => '.',
        'thousands' => '',
        'date_format' => 'Y-m-d',
        'amount_mode' => 'signed',
        'columns' => [
            'booked_at' => 'Date',
            'amount' => 'Amount',
            'dare' => 'Debit',
            'avere' => 'Credit',
            'description' => 'Description',
        ],
    ]));

    expect($layout['columns']['amount'])->toBe('Amount')
        ->and($layout['columns']['dare'])->toBe('')
        ->and($layout['columns']['avere'])->toBe('')
        ->and($layout['columns']['value_date'])->toBe('');
});

it('sopravvive al JSON avvolto nel markdown', function () {
    $layout = app(BankCsvLayoutDetector::class)->mappaturaDa(
        "Ecco la configurazione:\n```json\n".json_encode([
            'delimiter' => ';',
            'amount_mode' => 'dare_avere',
            'columns' => ['booked_at' => 'DATA', 'dare' => 'DARE', 'avere' => 'AVERE'],
        ])."\n```"
    );

    expect($layout['columns']['booked_at'])->toBe('DATA');
});

it('ricade sui default quando separatore o modalità non hanno senso', function () {
    $layout = app(BankCsvLayoutDetector::class)->mappaturaDa(json_encode([
        'delimiter' => 'punto e virgola',
        'decimal' => 'virgola',
        'amount_mode' => 'boh',
        'date_format' => '',
        'columns' => ['booked_at' => 'DATA', 'amount' => 'Importo'],
    ]));

    expect($layout['delimiter'])->toBe(';')
        ->and($layout['decimal'])->toBe(',')
        ->and($layout['amount_mode'])->toBe('signed')
        ->and($layout['date_format'])->toBe('d/m/Y');
});

it('si rifiuta di indovinare se non trova la colonna della data', function () {
    app(BankCsvLayoutDetector::class)->mappaturaDa(json_encode([
        'delimiter' => ';',
        'amount_mode' => 'signed',
        'columns' => ['booked_at' => '', 'amount' => 'Importo'],
    ]));
})->throws(RuntimeException::class, 'colonna della data');

it('si ferma se la risposta non è JSON', function () {
    app(BankCsvLayoutDetector::class)->mappaturaDa('Non riesco a capire questo file.');
})->throws(RuntimeException::class, 'non in JSON');

it('considera il riconoscimento spento senza chiave API', function () {
    expect(app(BankCsvLayoutDetector::class)->configured())->toBeTrue();

    config()->set('services.anthropic.api_key', '');
    expect(app(BankCsvLayoutDetector::class)->configured())->toBeFalse();

    // Il bottone sparisce, ma la pagina e l'import a mano restano.
    Livewire::test(ListBankTransactions::class)->assertSuccessful();
});
