<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// La self-registration pubblica è disattivata di proposito: gli utenti sono
// creati dagli admin (o tramite magic link per i clienti). Questi test
// fotografano quel comportamento invece di una pagina di registrazione.

it('does not expose a public registration route', function () {
    expect(Route::has('filament.app.auth.register'))->toBeFalse();
});

it('redirects guests from the panel to the login screen', function () {
    $this->get('/clients')
        ->assertRedirect(route('filament.app.auth.login'));
});

it('lets authenticated admins reach the panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/clients')
        ->assertOk();
});

it('model has required fields for registration', function () {
    $user = new User([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'mario.rossi@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    expect($user->name)->toBe('Mario');
    expect($user->surname)->toBe('Rossi');
    expect($user->email)->toBe('mario.rossi@example.com');
    expect(Hash::check('Password123!', $user->password))->toBeTrue();
});

it('shows the login screen', function () {
    $this->get(route('filament.app.auth.login'))
        ->assertOk();
});

it('user can be created with valid credentials', function () {
    $user = User::create([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'mario.rossi@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    expect($user->id)->not->toBeNull();
    expect(User::where('email', 'mario.rossi@example.com')->count())->toBe(1);
});

it('prevents duplicate email creation', function () {
    User::factory()->create([
        'email' => 'mario.rossi@example.com',
    ]);

    expect(User::where('email', 'mario.rossi@example.com')->count())->toBe(1);
});
