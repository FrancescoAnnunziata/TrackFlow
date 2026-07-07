<?php

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Reimbursement;
use App\Models\User;

function makeExpense(array $attributes = []): Expense
{
    return Expense::create(array_merge([
        'user_id' => User::factory()->create(['role' => 'member'])->id,
        'client_id' => Client::create(['name' => 'Cliente Test '.uniqid()])->id,
        'date' => now()->toDateString(),
        'amount' => 100,
        'notes' => 'Pranzo di lavoro',
    ], $attributes));
}

it('does not create a reimbursement for a company-card expense (default)', function () {
    $expense = makeExpense();

    expect($expense->fresh()->paid_with_personal_card)->toBeFalse();
    expect(Reimbursement::where('expense_id', $expense->id)->exists())->toBeFalse();
});

it('auto-creates a personal-card reimbursement mirroring the expense', function () {
    $expense = makeExpense(['paid_with_personal_card' => true, 'amount' => 55.40]);

    $reimbursement = Reimbursement::where('expense_id', $expense->id)->first();

    expect($reimbursement)->not->toBeNull();
    expect($reimbursement->type)->toBe(ReimbursementType::PersonalCard);
    expect($reimbursement->status)->toBe(ReimbursementStatus::Pending);
    expect($reimbursement->user_id)->toBe($expense->user_id);
    expect($reimbursement->client_id)->toBe($expense->client_id);
    expect((float) $reimbursement->amount)->toBe(55.40);
});

it('updates the linked reimbursement when the expense amount changes, keeping the status', function () {
    $expense = makeExpense(['paid_with_personal_card' => true, 'amount' => 30]);
    $reimbursement = Reimbursement::where('expense_id', $expense->id)->first();
    $reimbursement->update(['status' => ReimbursementStatus::Paid]);

    $expense->update(['amount' => 80]);

    $reimbursement->refresh();
    expect((float) $reimbursement->amount)->toBe(80.0);
    // Lo stato gestito a mano non viene resettato dall'aggiornamento.
    expect($reimbursement->status)->toBe(ReimbursementStatus::Paid);
});

it('removes the reimbursement when the expense switches back to company card', function () {
    $expense = makeExpense(['paid_with_personal_card' => true]);
    expect(Reimbursement::where('expense_id', $expense->id)->exists())->toBeTrue();

    $expense->update(['paid_with_personal_card' => false]);

    expect(Reimbursement::where('expense_id', $expense->id)->exists())->toBeFalse();
});

it('deletes the reimbursement when the expense is deleted', function () {
    $expense = makeExpense(['paid_with_personal_card' => true]);
    $id = $expense->id;
    expect(Reimbursement::where('expense_id', $id)->exists())->toBeTrue();

    $expense->delete();

    expect(Reimbursement::where('expense_id', $id)->exists())->toBeFalse();
});
