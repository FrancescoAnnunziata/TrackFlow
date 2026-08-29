<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reporting\FinancialOverviewBuilder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function speseConMovimento(): array
{
    $user = User::factory()->admin()->create();
    $conto = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank', 'opening_balance' => 0]);

    $spesa = Expense::create([
        'user_id' => $user->id, 'date' => '2026-06-15', 'amount' => 50, 'conto' => 'Ristorazione',
    ]);

    $movimento = BankTransaction::create([
        'bank_account_id' => $conto->id, 'booked_at' => '2026-06-15',
        'amount' => -50, 'description' => 'POS Ristorante', 'dedup_hash' => 'l1',
    ]);

    return [$user, $spesa, $movimento];
}

it('collega la spesa al movimento con cui è stata pagata', function () {
    [$user, $spesa, $movimento] = speseConMovimento();

    Livewire::actingAs($user)
        ->test(ListExpenses::class)
        ->callAction(
            TestAction::make('collegaMovimento')->table($spesa),
            ['bank_transaction_id' => $movimento->id],
        );

    expect($spesa->fresh()->bankTransaction->is($movimento))->toBeTrue();
});

it('il collegamento non è una riconciliazione e non tocca i conti', function () {
    [$user, $spesa, $movimento] = speseConMovimento();
    $spesa->update(['bank_transaction_id' => $movimento->id]);

    // Nessuna riga di riconciliazione, il movimento resta da riconciliare.
    expect($movimento->fresh()->reconciled)->toBeFalse()
        ->and($movimento->reconciliations()->count())->toBe(0);

    // E la spesa continua a contare una volta sola fra i costi dell'anno.
    $mesi = app(FinancialOverviewBuilder::class)->g8labsMonthly(2026);
    expect(collect($mesi)->firstWhere('mese', 6)['costi'])->toBe(50.0);
});

it('la spesa collegata non diventa un candidato alla riconciliazione', function () {
    [$user, $spesa, $movimento] = speseConMovimento();
    $spesa->update(['bank_transaction_id' => $movimento->id]);

    $suggerimenti = app(MatchSuggestionService::class)->suggestions($movimento->fresh());

    expect($suggerimenti->contains(fn (array $s): bool => $s['model'] instanceof Expense))->toBeFalse();
});

it('si può scollegare', function () {
    [$user, $spesa, $movimento] = speseConMovimento();
    $spesa->update(['bank_transaction_id' => $movimento->id]);

    Livewire::actingAs($user)
        ->test(ListExpenses::class)
        ->callAction(
            TestAction::make('collegaMovimento')->table($spesa),
            ['bank_transaction_id' => null],
        );

    expect($spesa->fresh()->bank_transaction_id)->toBeNull();
});
