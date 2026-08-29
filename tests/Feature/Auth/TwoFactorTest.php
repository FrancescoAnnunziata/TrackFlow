<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('manda chi non ha i due fattori sulla pagina di configurazione', function () {
    $user = User::factory()->admin()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get('/clients')
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

it('lascia entrare chi i due fattori li ha gia attivi', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/clients')->assertOk();
});

// I clienti sono l'eccezione voluta: entrano saltuariamente, spesso da un magic
// link, e se perdono il telefono l'unico help desk siamo noi.
it('non chiede i due fattori ai clienti', function () {
    $client = User::factory()->withoutTwoFactor()->create(['role' => 'client']);

    $this->actingAs($client)->get('/')->assertOk();
});

it('continua a chiederli a membri e commercialisti', function (string $role) {
    $user = User::factory()->withoutTwoFactor()->create(['role' => $role]);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
})->with(['member', 'accountant']);

// Cambio password obbligatorio e due fattori obbligatori sono due cancelli che
// rimandano l'uno alla pagina dell'altro: senza un ordine esplicito si finisce
// in un ciclo di redirect e l'utente non entra piu'. L'ordine scelto e'
// "prima la password, poi i due fattori": legare un'app di autenticazione a un
// account che ha ancora la password temporanea e' il verso sbagliato.
it('non manda in loop chi deve sia cambiare password sia attivare i due fattori', function () {
    $user = User::factory()->admin()->withoutTwoFactor()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->get('/clients')
        ->assertRedirect(route('filament.app.pages.change-password'));

    // La pagina di cambio password deve essere raggiungibile SENZA i due
    // fattori, altrimenti rimbalza sul setup che a sua volta rimbalza qui.
    $this->actingAs($user)
        ->get(route('filament.app.pages.change-password'))
        ->assertOk();
});

it('dopo il cambio password chiede comunque i due fattori', function () {
    $user = User::factory()->admin()->withoutTwoFactor()->create(['must_change_password' => false]);

    $this->actingAs($user)
        ->get(route('filament.app.pages.change-password'))
        ->assertOk();

    $this->actingAs($user)
        ->get('/clients')
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

it('tiene segreto e codici di recupero cifrati nel database', function () {
    $user = User::factory()->create();
    $user->saveAppAuthenticationRecoveryCodes(['codice-uno', 'codice-due']);

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->app_authentication_secret)->not->toBe(User::factory()::TWO_FACTOR_SECRET)
        ->and($raw->app_authentication_recovery_codes)->not->toContain('codice-uno')
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['codice-uno', 'codice-due']);
});

it('non espone segreto e codici quando l utente viene serializzato', function () {
    $user = User::factory()->create();

    expect(array_keys($user->toArray()))
        ->not->toContain('app_authentication_secret')
        ->not->toContain('app_authentication_recovery_codes');
});

it('permette a un admin di azzerare i due fattori di un utente', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect($target->getAppAuthenticationSecret())->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->callAction(TestAction::make('resetTwoFactor')->table($target));

    expect($target->refresh()->getAppAuthenticationSecret())->toBeNull()
        ->and($target->getAppAuthenticationRecoveryCodes())->toBeNull();
});

it('non offre l azzeramento dei due fattori a chi non e admin', function () {
    $member = User::factory()->create(['role' => 'member']);
    $target = User::factory()->create();

    Livewire::actingAs($member)
        ->test(ListUsers::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($target));
});

// Con i due fattori obbligatori, una sessione da 2 ore significa rifare
// password e codice dell'app più volte al giorno. Una settimana è la scelta
// voluta: se qualcuno la riabbassa senza accorgersene, questo test lo dice.
it('tiene la sessione valida una settimana, così il login torna al massimo ogni 7 giorni', function () {
    expect((int) config('session.lifetime'))->toBe(7 * 24 * 60)
        ->and(config('session.expire_on_close'))->toBeFalse();
});
