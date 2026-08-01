<?php

use App\Filament\Pages\DashboardFinanziaria;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('opens the movements modal for a month and filters by type', function () {
    $this->actingAs(User::factory()->admin()->create());
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);

    $entrata = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-01-10', 'amount' => 500,
        'direction' => 'in', 'description' => 'Accredito cliente', 'dedup_hash' => 'm1',
    ]);
    $uscitaRiconciliata = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-01-12', 'amount' => -80,
        'direction' => 'out', 'description' => 'Uscita con costo', 'dedup_hash' => 'm2',
    ]);
    $uscitaNonAttribuita = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-01-15', 'amount' => -40,
        'direction' => 'out', 'description' => 'Uscita senza documento', 'dedup_hash' => 'm3',
    ]);
    // Un giroconto in uscita: non deve mai comparire.
    $giroA = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-01-20', 'amount' => -200,
        'direction' => 'out', 'description' => 'Giroconto', 'dedup_hash' => 'm4',
    ]);
    $giroB = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-01-20', 'amount' => 200,
        'direction' => 'in', 'description' => 'Giroconto', 'dedup_hash' => 'm5',
    ]);
    $group = min($giroA->id, $giroB->id);
    $giroA->update(['transfer_group_id' => $group]);
    $giroB->update(['transfer_group_id' => $group]);

    $costo = Costo::create(['date' => '2026-01-12', 'description' => 'Costo', 'amount' => 80, 'vat_amount' => 0]);
    app(ReconciliationService::class)->attach($uscitaRiconciliata, $costo, 80);

    // L'azione si monta con gli argomenti della cella cliccata.
    Livewire::test(DashboardFinanziaria::class)
        ->mountAction('movimenti', ['tipo' => 'entrate', 'mese' => 1, 'anno' => 2026])
        ->assertActionMounted('movimenti');

    $page = new DashboardFinanziaria;

    // Entrate gennaio: solo l'accredito (il giroconto in entrata è escluso).
    $entrate = $page->movimenti('entrate', 1, 2026)->pluck('id');
    expect($entrate)->toContain($entrata->id);
    expect($entrate)->not->toContain($giroB->id);
    expect($entrate)->not->toContain($uscitaRiconciliata->id);

    // Uscite gennaio: entrambe le uscite, ma non i giroconti.
    $uscite = $page->movimenti('uscite', 1, 2026)->pluck('id');
    expect($uscite)->toContain($uscitaRiconciliata->id, $uscitaNonAttribuita->id);
    expect($uscite)->not->toContain($giroA->id);

    // Uscite non riconciliate gennaio: solo quella senza documento.
    $nonAttrib = $page->movimenti('non_attribuite', 1, 2026)->pluck('id');
    expect($nonAttrib)->toContain($uscitaNonAttribuita->id);
    expect($nonAttrib)->not->toContain($uscitaRiconciliata->id);
    expect($nonAttrib)->not->toContain($giroA->id);

    // Su tutto l'anno (mese 0) la non riconciliata resta inclusa.
    expect($page->movimenti('non_attribuite', 0, 2026)->pluck('id'))
        ->toContain($uscitaNonAttribuita->id);
});
