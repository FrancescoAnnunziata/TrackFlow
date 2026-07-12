<?php

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('opens and submits "Segna come costo" on a movement without erroring', function () {
    $this->actingAs(User::factory()->admin()->create());
    // Un costo preesistente rende "Trasferte" una categoria valida nella select.
    Costo::create(['date' => '2026-01-01', 'description' => 'seed', 'category' => 'Trasferte', 'amount' => 1, 'vat_amount' => 0]);
    $supplier = Supplier::create(['name' => 'Autostrade per l\'Italia']);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-02', 'amount' => -17.30,
        'direction' => 'out', 'description' => 'Pedaggio autostradale', 'dedup_hash' => 'p1',
    ]);

    // Il mount dell'azione (form sul movimento) non deve rompersi sul campo Fornitore.
    Livewire::test(ListBankTransactions::class)
        ->callAction(TestAction::make('segnaCosto')->table($tx), [
            'description' => 'Pedaggio', 'category' => 'Trasferte', 'supplier_id' => $supplier->id, 'amount' => 17.30,
        ])
        ->assertHasNoActionErrors();

    $costo = Costo::where('bank_transaction_id', $tx->id)->first();
    expect($costo)->not->toBeNull();
    expect($costo->category)->toBe('Trasferte');
    expect($costo->supplier_id)->toBe($supplier->id);
    expect($tx->fresh()->reconciled)->toBeTrue();
});
