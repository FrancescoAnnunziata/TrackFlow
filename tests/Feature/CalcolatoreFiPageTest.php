<?php

use App\Filament\Pages\CalcolatoreFi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('renders for the owner and embeds the calculator iframe', function () {
    $owner = User::factory()->create([
        'email' => 'giorgio.giotto@g8labs.it',
        'role' => 'admin',
    ]);

    actingAs($owner);

    expect(CalcolatoreFi::canAccess())->toBeTrue();

    Livewire::test(CalcolatoreFi::class)
        ->assertOk()
        ->assertSee('srcdoc', escape: false)
        ->assertSee('Calcolatore FI');
});

it('denies access to any other user', function () {
    $other = User::factory()->create([
        'email' => 'someone.else@example.com',
        'role' => 'admin',
    ]);

    actingAs($other);

    expect(CalcolatoreFi::canAccess())->toBeFalse();
});
