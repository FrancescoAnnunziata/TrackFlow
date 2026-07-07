<?php

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Filament\Resources\Reimbursements\Pages\CreateReimbursement;
use App\Models\Reimbursement;
use App\Models\User;
use Livewire\Livewire;

it('offers exactly the three reimbursement types', function () {
    expect(collect(ReimbursementType::cases())->map->value->all())
        ->toBe(['trasferta', 'carta_personale', 'manuale']);
});

it('lets a member create a reimbursement attributed to themselves', function () {
    $member = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($member)
        ->test(CreateReimbursement::class)
        ->fillForm([
            'type' => ReimbursementType::Travel->value,
            'date' => now()->toDateString(),
            'amount' => 42.50,
            'notes' => 'Trasferta cliente X',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $reimbursement = Reimbursement::query()->latest('id')->first();
    expect($reimbursement->user_id)->toBe($member->id);
    expect($reimbursement->type)->toBe(ReimbursementType::Travel);
    expect($reimbursement->status)->toBe(ReimbursementStatus::Pending);
});

it('scopes visibility: a member sees only their own, an admin sees all', function () {
    $memberA = User::factory()->create(['role' => 'member']);
    $memberB = User::factory()->create(['role' => 'member']);
    $admin = User::factory()->create(['role' => 'admin']);

    $ownedByA = Reimbursement::factory()->create(['user_id' => $memberA->id]);
    $ownedByB = Reimbursement::factory()->create(['user_id' => $memberB->id]);

    expect($memberA->can('view', $ownedByA))->toBeTrue();
    expect($memberA->can('view', $ownedByB))->toBeFalse();
    expect($admin->can('view', $ownedByA))->toBeTrue();
    expect($admin->can('view', $ownedByB))->toBeTrue();
});

it('keeps reimbursements hidden from clients', function () {
    expect(User::factory()->make(['role' => 'client'])->can('viewAny', Reimbursement::class))->toBeFalse();
    expect(User::factory()->make(['role' => 'member'])->can('viewAny', Reimbursement::class))->toBeTrue();
});
