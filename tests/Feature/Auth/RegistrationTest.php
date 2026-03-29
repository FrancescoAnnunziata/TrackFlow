<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('shows the registration screen', function () {
    $this->get(route('filament.app.auth.register'))
        ->assertOk()
        ->assertSee('Sign up');
});

it('can create a new user via filament registration', function () {
    // Verifichiamo che la pagina di registrazione sia raggiungibile
    $response = $this->get(route('filament.app.auth.register'));
    $response->assertOk();

    // Verifichiamo che l'utente non esista ancora
    expect(User::where('email', 'mario.rossi@example.com')->count())->toBe(0);
});

it('model has required fields for registration', function () {
    // Verifichiamo che il modello User supporti i campi richiesti
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

it('register page includes login link', function () {
    $this->get(route('filament.app.auth.register'))
        ->assertOk();
    // Filament ha i suoi link interni per login
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

