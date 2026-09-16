<?php

use App\Http\Middleware\ReauthenticateWeekly;
use App\Listeners\RecordLastLogin;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

// Quanto si resta dentro senza rifare l'accesso: una settimana, non un giorno
// (la sessione si rinnova da sola) e non un anno (il «Ricordami» di serie).

it('non chiede di rifare l\'accesso dentro la settimana', function () {
    $admin = User::factory()->admin()->create(['last_login_at' => now()->subDays(6)]);

    $this->actingAs($admin)
        ->get('/clients')
        ->assertOk();
});

it('rimanda al login passata la settimana', function () {
    $admin = User::factory()->admin()->create([
        'last_login_at' => now()->subDays(ReauthenticateWeekly::DAYS + 1),
    ]);

    $this->actingAs($admin)
        ->get('/clients')
        ->assertRedirect(route('filament.app.auth.login'));

    // E l'utente è davvero fuori: il logout azzera anche il token di
    // «Ricordami», così il cookie non lo riporta dentro un istante dopo.
    expect(Auth::check())->toBeFalse();
});

it('fa ripartire la settimana dal primo accesso di chi non ne ha mai fatto uno', function () {
    $admin = User::factory()->admin()->create(['last_login_at' => null]);

    $this->actingAs($admin)
        ->get('/clients')
        ->assertOk();

    expect($admin->fresh()->last_login_at)->not->toBeNull();
});

it('non butta fuori i clienti, che entrano dal magic link', function () {
    $client = Client::create(['name' => 'Acme SpA']);
    $contatto = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
        'last_login_at' => now()->subYear(),
    ]);

    $this->actingAs($contatto)
        ->get('/quotes')
        ->assertOk();
});

it('segna la data quando l\'accesso è vero', function () {
    $admin = User::factory()->admin()->create(['last_login_at' => null]);

    event(new Login('web', $admin, false));

    expect($admin->fresh()->last_login_at)->not->toBeNull();
});

it('non fa ripartire il conto quando a riportare dentro è il cookie «Ricordami»', function () {
    $tempoFa = now()->subDays(6);
    $admin = User::factory()->admin()->create(['last_login_at' => $tempoFa]);

    // Un guard che dichiara di aver riportato dentro l'utente col cookie.
    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('viaRemember')->andReturnTrue();
    Auth::shouldReceive('guard')->with('web')->andReturn($guard);

    (new RecordLastLogin)->handle(new Login('web', $admin, true));

    expect($admin->fresh()->last_login_at->timestamp)->toBe($tempoFa->timestamp);
});

it('tiene il cookie «Ricordami» agganciato alla stessa settimana', function () {
    $durata = (new ReflectionMethod(Auth::guard(), 'getRememberDuration'));
    $durata->setAccessible(true);

    expect($durata->invoke(Auth::guard()))->toBe(ReauthenticateWeekly::DAYS * 24 * 60);
});
