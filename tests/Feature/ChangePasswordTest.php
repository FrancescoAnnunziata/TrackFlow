<?php

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets a user change password with the correct current password', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->fillForm([
            'currentPassword' => 'password',
            'password' => 'NewStrongPass123!',
            'passwordConfirmation' => 'NewStrongPass123!',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('NewStrongPass123!', $user->refresh()->password))->toBeTrue();
});

it('rejects a voluntary change when the current password is wrong', function () {
    $user = User::factory()->create(['must_change_password' => false]);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->fillForm([
            'currentPassword' => 'wrong-password',
            'password' => 'NewStrongPass123!',
            'passwordConfirmation' => 'NewStrongPass123!',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('forces a password change without asking the current one and clears the flag', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->fillForm([
            'password' => 'NewStrongPass123!',
            'passwordConfirmation' => 'NewStrongPass123!',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect(Hash::check('NewStrongPass123!', $user->password))->toBeTrue();
    expect($user->must_change_password)->toBeFalse();
});
