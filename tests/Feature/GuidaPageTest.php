<?php

use App\Filament\Pages\Guida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renderizza il manuale per gli admin', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSuccessful()
        // I due punti che si sbagliano più spesso devono restare nel manuale.
        ->assertSee('Alsea', escape: false)
        ->assertSee('giorgio@g8labs.it', escape: false);
});

it('tiene il manuale fuori dalla portata dei non admin', function () {
    expect(Guida::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => 'member']));
    expect(Guida::canAccess())->toBeFalse();
});

it('spiega da dove arrivano le fatture passive', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('Importa da Fatture in Cloud', escape: false)
        ->assertSee('tre ore', escape: false);
});

it('spiega che le fatture estere vanno caricate a mano prima di riconciliare', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('Fatture estere', escape: false)
        // Il punto che fa fallire la riconciliazione se lo si ignora.
        ->assertSee('Importo EUR (cambio)', escape: false);
});

it('dice a Paola dove cercare i PDF delle fatture estere', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('amministrazione@g8labs.it', escape: false);
});

it('avverte Paola che segnare come costo ha un prezzo', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('IVA non si detrae', escape: false)
        ->assertSee('10–15', escape: false)
        // Il collegamento spesa → pagamento non deve sembrare una riconciliazione.
        ->assertSee('Collega movimento', escape: false);
});
