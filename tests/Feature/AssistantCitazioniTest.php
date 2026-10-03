<?php

use App\Assistant\AssistantRunner;
use App\Assistant\Tools\ReadBankMovementsTool;
use App\Assistant\Tools\ReadPassiveInvoicesTool;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * L'assistente ha elencato a Paola cinque fatture passive che non esistevano,
 * costruite dai nomi dei negozi letti nelle causali bancarie. Due difese, e
 * questi test tengono ferme entrambe: gli strumenti consegnano id *e link* (il
 * modello li copia, non li compone) e il prompt vieta esplicitamente di
 * promuovere una causale a fattura.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('mette il link accanto a ogni fattura passiva che elenca', function () {
    $fornitore = Supplier::create(['name' => 'Scaling Tales']);
    $fattura = PassiveInvoice::create([
        'supplier_id' => $fornitore->id, 'number' => '228', 'type' => 'expense',
        'document_date' => '2026-09-06', 'amount_net' => 1584.78, 'amount_vat' => 0,
        'amount_gross' => 1584.78, 'payment_status' => PassiveInvoice::STATUS_PAID,
    ]);

    $esito = app(ReadPassiveInvoicesTool::class)->run([]);

    expect($esito->content)
        ->toContain('id='.$fattura->id)
        ->toContain('/passive-invoices/'.$fattura->id);
});

it('mette il link accanto a ogni movimento bancario', function () {
    $conto = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $mov = BankTransaction::create([
        'bank_account_id' => $conto->id, 'booked_at' => '2026-09-19', 'amount' => -55.00,
        'direction' => 'out', 'description' => 'RISTORANTE SIRANI, BRESCIA, IT', 'dedup_hash' => 'sir1',
    ]);

    $esito = app(ReadBankMovementsTool::class)->run([]);

    expect($esito->content)
        ->toContain('id='.$mov->id)
        ->toContain('/bank-transactions/'.$mov->id);
});

it('il link si costruisce anche senza pannello corrente, come in coda', function () {
    // In coda non c'è nessuna richiesta al pannello. Filament ricade su quello
    // predefinito, quindi il link esce comunque: questo test lo tiene fermo,
    // perché è la condizione in cui girano davvero i turni.
    Filament::setCurrentPanel(null);

    $conto = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    BankTransaction::create([
        'bank_account_id' => $conto->id, 'booked_at' => '2026-09-19', 'amount' => -55.00,
        'direction' => 'out', 'description' => 'RISTORANTE SIRANI', 'dedup_hash' => 'sir2',
    ]);

    $esito = app(ReadBankMovementsTool::class)->run([]);

    expect($esito->isError)->toBeFalse()
        ->and($esito->content)->toContain('RISTORANTE SIRANI')
        ->and($esito->content)->toContain('/bank-transactions/');
});

it('il prompt vieta di promuovere una causale bancaria a fattura e impone id e link', function () {
    $metodo = (new ReflectionClass(AssistantRunner::class))->getMethod('staticSystemPrompt');
    $metodo->setAccessible(true);
    $prompt = $metodo->invoke(app(AssistantRunner::class), true);

    expect($prompt)
        ->toContain('UNA CAUSALE BANCARIA NON È UNA FATTURA')
        ->toContain('OGNI DOCUMENTO CHE NOMINI VA CON IL SUO id E IL SUO LINK')
        ->toContain('COSA NON VEDI')
        ->toContain('corrispettivi');
});

it('la regola vale anche per il commercialista, che ha solo la lettura', function () {
    $metodo = (new ReflectionClass(AssistantRunner::class))->getMethod('staticSystemPrompt');
    $metodo->setAccessible(true);

    expect($metodo->invoke(app(AssistantRunner::class), false))
        ->toContain('UNA CAUSALE BANCARIA NON È UNA FATTURA');
});
