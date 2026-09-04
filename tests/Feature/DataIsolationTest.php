<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Hours\Pages\ListHours;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
    $hourA->update(['client_id' => $clientA->id]);

    $hourB = Hour::create([
        'user_id' => $userB->id,
        'date' => '2026-03-11',
        'hours' => 1.0,
        'billable' => false,
    ]);
    $hourB->update(['client_id' => $clientB->id]);

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

// Un'ora appartiene a UN cliente solo: il molti-a-molti c'era ma in cinque mesi
// non è mai stato usato, e complicava form, filtro, export e calcolo delle ore
// fatturabili. Se qualcuno lo reintroduce, questo test lo ferma.
it('lega ogni ora a un cliente solo', function () {
    $cliente = Client::create(['name' => 'Cliente Unico']);
    $ora = Hour::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $cliente->id,
        'date' => '2026-06-10',
        'hours' => 3,
        'billable' => true,
    ]);

    expect($ora->client->is($cliente))->toBeTrue()
        ->and(Schema::hasTable('client_hour'))->toBeFalse();
});

it('mostra a un utente cliente solo le ore fatte sui suoi clienti', function () {
    $suo = Client::create(['name' => 'Cliente Suo']);
    $altrui = Client::create(['name' => 'Cliente Altrui']);

    $operatore = User::factory()->create();
    $sue = Hour::create(['user_id' => $operatore->id, 'client_id' => $suo->id, 'date' => '2026-06-10', 'hours' => 2, 'billable' => true]);
    $altre = Hour::create(['user_id' => $operatore->id, 'client_id' => $altrui->id, 'date' => '2026-06-11', 'hours' => 4, 'billable' => true]);

    $utenteCliente = User::factory()->create(['role' => 'client']);
    $utenteCliente->clients()->attach($suo->id);

    Livewire::actingAs($utenteCliente)
        ->test(ListHours::class)
        ->assertCanSeeTableRecords([$sue])
        ->assertCanNotSeeTableRecords([$altre]);
});
