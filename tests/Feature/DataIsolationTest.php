<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows all clients in filament clients index', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Client::create([
        'name' => 'Cliente Mario',
    ]);

    Client::create([
        'name' => 'Cliente Luigi',
    ]);

    $this->actingAs($userA)
        ->get('/clients')
        ->assertOk()
        ->assertSee('Cliente Mario')
        ->assertSee('Cliente Luigi');
});

it('shows only owned hours in filament hours index', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $clientA = Client::create([
        'name' => 'Cliente Ore A',
    ]);

    $clientB = Client::create([
        'name' => 'Cliente Ore B',
    ]);

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

    $this->actingAs($userA)
        ->get('/hours')
        ->assertOk()
        ->assertSee('Cliente Ore A')
        ->assertDontSee('Cliente Ore B');
});

it('shows only owned expenses in filament expenses index', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $clientA = Client::create([
        'name' => 'Cliente Spese A',
    ]);

    $clientB = Client::create([
        'name' => 'Cliente Spese B',
    ]);

    Expense::create([
        'user_id' => $userA->id,
        'client_id' => $clientA->id,
        'date' => '2026-03-10',
        'amount' => 100,
    ]);

    Expense::create([
        'user_id' => $userB->id,
        'client_id' => $clientB->id,
        'date' => '2026-03-11',
        'amount' => 220,
    ]);

    $this->actingAs($userA)
        ->get('/expenses')
        ->assertOk()
        ->assertSee('Cliente Spese A')
        ->assertDontSee('Cliente Spese B');
});


