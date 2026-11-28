<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\SicurezzaAccesso;
use App\Http\Middleware\RequireTwoFactorExceptClients;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function conPasskey(User $user): User
{
    $user->passkeys()->create([
        'name' => 'MacBook',
        'credential_id' => base64_encode(random_bytes(16)),
        'credential' => ['publicKey' => 'test'],
    ]);

    return $user;
}

it('offers the passkey sign-in button on the login page', function () {
    Livewire::test(Login::class)->assertOk();

    $this->get(filament()->getLoginUrl())
        ->assertOk()
        ->assertSee('Accedi con passkey');
});

it('lets an OTP user open the security page and see the passkey section', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SicurezzaAccesso::class)
        ->assertOk()
        ->assertSee('Aggiungi una passkey');
});

it('counts a passkey as the second factor required to work in TrackFlow', function () {
    $user = conPasskey(User::factory()->admin()->create());
    $this->actingAs($user);

    $passed = false;
    app(RequireTwoFactorExceptClients::class)->handle(request(), function () use (&$passed) {
        $passed = true;

        return response('ok');
    });

    expect($passed)->toBeTrue();
});

it('serves the passkey endpoints from the panel, not from laravel/passkeys', function () {
    expect(Route::has('filament.app.passkey.login'))->toBeTrue();
    expect(Route::getRoutes()->getByName('passkey.login'))->toBeNull();
});
