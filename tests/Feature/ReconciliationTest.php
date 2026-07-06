<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Reconciliation;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePaidScenario(): array
{
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Cliente Reco SpA', 'vat_number' => 'IT99999999999']);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '77/2026',
        'issue_date' => '2026-06-10', 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
        'vat_rate' => 22, 'status' => 'sent',
    ]);
    $invoice->items()->create(['name' => 'Consulenza', 'qty' => 1, 'net_price' => 1000, 'vat_kind' => 'standard', 'line_kind' => 'consulting', 'sort' => 0]);
    $invoice->refresh();

    $account = BankAccount::create(['name' => 'Conto', 'bank_key' => 'generic', 'opening_balance' => 0]);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15',
        'amount' => $invoice->total(), 'description' => 'Bonifico Cliente Reco SpA', 'dedup_hash' => 'h1',
    ]);

    return [$invoice, $tx];
}

it('ranks the matching invoice first with high confidence', function () {
    [$invoice, $tx] = makePaidScenario();

    $suggestions = app(MatchSuggestionService::class)->suggestions($tx);

    expect($suggestions)->not->toBeEmpty();
    expect($suggestions->first()['model']->is($invoice))->toBeTrue();
    expect($suggestions->first()['confidence'])->toBeGreaterThanOrEqual(90);
});

it('marks the invoice paid and the transaction reconciled on attach, and reverts on detach', function () {
    [$invoice, $tx] = makePaidScenario();

    $service = app(ReconciliationService::class);
    $service->attach($tx, $invoice, (float) $tx->amount);

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($invoice->fresh()->status)->toBe('paid');

    $service->detach(Reconciliation::first());

    expect($tx->fresh()->reconciled)->toBeFalse();
    expect($invoice->fresh()->status)->toBe('sent');
});

it('auto-reconciles only high-confidence exact matches', function () {
    [$invoice, $tx] = makePaidScenario();

    $this->artisan('finance:auto-reconcile', ['--min-confidence' => 90])->assertSuccessful();

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($invoice->fresh()->status)->toBe('paid');
    expect(Reconciliation::where('matched_by', 'auto')->count())->toBe(1);
});
