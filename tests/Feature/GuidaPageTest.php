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

it('dice che l import automatico prende solo le spese registrate su Fatture in Cloud', function () {
    // È il passaggio che, se manca, fa cercare per ore una fattura che è
    // ferma su Fatture in Cloud: l'automatismo non la porta mai da solo.
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('Spese da registrare', escape: false)
        ->assertSee('Registrale tutte', escape: false)
        // E che quella pagina nasconde le righe se non si rimpicciolisce.
        ->assertSee('rimpicciolisci con lo zoom', escape: false)
        ->assertSee('solo il totale', escape: false);
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

it('dice chi può scaricare quale estratto conto', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('Vivid Business', escape: false)
        ->assertSee('Chiedi a Giorgio', escape: false);
});

it('spiega come si registra un preventivo accettato fuori da TrackFlow', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(Guida::class)
        ->assertSee('Registra accettazione', escape: false)
        // I due punti che rendono la registrazione una prova e non un clic.
        ->assertSee('Allega la prova', escape: false)
        ->assertSee('Carica copia firmata', escape: false);
});
