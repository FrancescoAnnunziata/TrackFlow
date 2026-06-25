<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Hours\Pages\ListHours;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('shows all clients in filament clients index', function () {
    // L'anagrafica clienti è riservata agli admin (ClientResource::canViewAny).
    $admin = User::factory()->admin()->create();

    Client::create(['name' => 'Cliente Mario']);
    Client::create(['name' => 'Cliente Luigi']);

    $this->actingAs($admin)
        ->get('/clients')
        ->assertOk()
        ->assertSee('Cliente Mario')
        ->assertSee('Cliente Luigi');
});

it('shows only owned hours in filament hours index', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $clientA = Client::create(['name' => 'Cliente Ore A']);
    $clientB = Client::create(['name' => 'Cliente Ore B']);

    $hourA = Hour::create([
        'user_id' => $userA->id,
        'date' => '2026-03-10',
        'hours' => 2.5,
        'billable' => true,
    ]);
    $hourA->clients()->attach($clientA);

    $hourB = Hour::create([
        'user_id' => $userB->id,
        'date' => '2026-03-11',
        'hours' => 1.0,
        'billable' => false,
    ]);
    $hourB->clients()->attach($clientB);

    // Isolamento verificato sui record della tabella (non sull'HTML grezzo,
    // dove i nomi cliente comparirebbero anche nelle opzioni dei filtri).
    Livewire::actingAs($userA)
        ->test(ListHours::class)
        ->assertCanSeeTableRecords([$hourA])
        ->assertCanNotSeeTableRecords([$hourB]);
});

it('shows only owned expenses in filament expenses index', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $clientA = Client::create(['name' => 'Cliente Spese A']);
    $clientB = Client::create(['name' => 'Cliente Spese B']);

    $expenseA = Expense::create([
        'user_id' => $userA->id,
        'client_id' => $clientA->id,
        'date' => '2026-03-10',
        'amount' => 100,
    ]);

    $expenseB = Expense::create([
        'user_id' => $userB->id,
        'client_id' => $clientB->id,
        'date' => '2026-03-11',
        'amount' => 220,
    ]);

    Livewire::actingAs($userA)
        ->test(ListExpenses::class)
        ->assertCanSeeTableRecords([$expenseA])
        ->assertCanNotSeeTableRecords([$expenseB]);
});
