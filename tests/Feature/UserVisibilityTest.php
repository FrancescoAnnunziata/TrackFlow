<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Livewire\Livewire;

it('lets a member view the users list', function () {
    $member = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($member)
        ->test(ListUsers::class)
        ->assertSuccessful();
});

it('does not let a member create or edit users', function () {
    $member = User::factory()->create(['role' => 'member']);
    $target = User::factory()->create(['role' => 'member']);

    expect($member->can('create', User::class))->toBeFalse();
    expect($member->can('update', $target))->toBeFalse();
    expect($member->can('delete', $target))->toBeFalse();
});

it('keeps the users list hidden from clients', function () {
    expect(User::factory()->make(['role' => 'client'])->can('viewAny', User::class))->toBeFalse();
    expect(User::factory()->make(['role' => 'member'])->can('viewAny', User::class))->toBeTrue();
});
