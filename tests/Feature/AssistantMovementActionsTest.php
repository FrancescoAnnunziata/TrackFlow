<?php

use App\Assistant\Tools\ProposeCostTool;
use App\Assistant\Tools\ProposeTransferTool;
use App\Filament\Pages\AssistenteAi;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function owner(): User
{
    return User::factory()->admin()->create(['email' => 'giorgio.giotto@g8labs.it']);
}

it('proposes a cost, and confirming creates+reconciles it', function () {
    $this->actingAs(owner());
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-07-06', 'amount' => -12.50,
        'direction' => 'out', 'description' => 'AUTOSTRADE PER ITALIA', 'dedup_hash' => 'c1',
    ]);

    $res = app(ProposeCostTool::class)->run(['movement_id' => $tx->id, 'category' => 'Trasferte', 'description' => 'Pedaggio']);
    expect($res->isError)->toBeFalse();
    expect($res->action['type'])->toBe('cost');
    expect($res->action['amount'])->toBe(12.5);

    // Conferma dalla pagina (dispatch per tipo).
    $thread = AssistantThread::create(['user_id' => auth()->id()]);
    $msg = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'proposta', 'status' => 'done',
        'actions' => [['id' => 'a1', 'status' => 'pending'] + $res->action],
    ]);

    Livewire::test(AssistenteAi::class)->call('openThread', $thread->id)->call('confirmProposal', $msg->id, 'a1');

    $costo = Costo::where('bank_transaction_id', $tx->id)->first();
    expect($costo)->not->toBeNull();
    expect($costo->category)->toBe('Trasferte');
    expect($tx->fresh()->reconciled)->toBeTrue();
    expect(AssistantMessage::find($msg->id)->actions[0]['status'])->toBe('applied');
});

it('proposes a transfer, and confirming pairs the two movements', function () {
    $this->actingAs(owner());
    $inbank = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $vivid = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $out = BankTransaction::create(['bank_account_id' => $inbank->id, 'booked_at' => '2026-07-06', 'amount' => -7000, 'direction' => 'out', 'description' => 'A Fav G8LABS', 'dedup_hash' => 't1']);
    $in = BankTransaction::create(['bank_account_id' => $vivid->id, 'booked_at' => '2026-07-06', 'amount' => 7000, 'direction' => 'in', 'description' => 'Trasferimento fondi', 'dedup_hash' => 't2']);

    $res = app(ProposeTransferTool::class)->run(['movement_id' => $out->id, 'twin_ids' => [$in->id]]);
    expect($res->isError)->toBeFalse();
    expect($res->action['type'])->toBe('transfer');
    expect($res->action['net'])->toBe(0.0);

    $thread = AssistantThread::create(['user_id' => auth()->id()]);
    $msg = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'proposta', 'status' => 'done',
        'actions' => [['id' => 'g1', 'status' => 'pending'] + $res->action],
    ]);

    Livewire::test(AssistenteAi::class)->call('openThread', $thread->id)->call('confirmProposal', $msg->id, 'g1');

    $group = min($out->id, $in->id);
    expect($out->fresh()->transfer_group_id)->toBe($group);
    expect($in->fresh()->transfer_group_id)->toBe($group);
    expect(AssistantMessage::find($msg->id)->actions[0]['status'])->toBe('applied');
});

it('proposes a one-to-many partita di giro (a reimbursement against several uscite)', function () {
    $this->actingAs(owner());
    $inbank = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $vivid = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    // Le tre uscite MEDBOOKS e il rimborso di +279 che le compensa.
    $u1 = BankTransaction::create(['bank_account_id' => $inbank->id, 'booked_at' => '2026-06-29', 'amount' => -58, 'direction' => 'out', 'description' => 'POS MEDBOOKS', 'dedup_hash' => 'p1']);
    $u2 = BankTransaction::create(['bank_account_id' => $inbank->id, 'booked_at' => '2026-06-29', 'amount' => -58, 'direction' => 'out', 'description' => 'POS MEDBOOKS', 'dedup_hash' => 'p2']);
    $u3 = BankTransaction::create(['bank_account_id' => $inbank->id, 'booked_at' => '2026-06-29', 'amount' => -163, 'direction' => 'out', 'description' => 'POS MEDBOOKS', 'dedup_hash' => 'p3']);
    $rimborso = BankTransaction::create(['bank_account_id' => $vivid->id, 'booked_at' => '2026-07-14', 'amount' => 279, 'direction' => 'in', 'description' => 'Restituzione a G8Labs', 'dedup_hash' => 'r1']);

    $res = app(ProposeTransferTool::class)->run([
        'movement_id' => $rimborso->id,
        'twin_ids' => [$u1->id, $u2->id, $u3->id],
    ]);
    expect($res->isError)->toBeFalse();
    expect($res->action['type'])->toBe('transfer');
    expect($res->action['net'])->toBe(0.0);
    expect($res->action['twin_ids'])->toHaveCount(3);

    $thread = AssistantThread::create(['user_id' => auth()->id()]);
    $msg = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'proposta', 'status' => 'done',
        'actions' => [['id' => 'pg1', 'status' => 'pending'] + $res->action],
    ]);

    Livewire::test(AssistenteAi::class)->call('openThread', $thread->id)->call('confirmProposal', $msg->id, 'pg1');

    // Tutti e quattro nello stesso gruppo (l'id più piccolo come àncora).
    $group = collect([$u1, $u2, $u3, $rimborso])->min->id;
    expect($rimborso->fresh()->transfer_group_id)->toBe($group);
    expect($u1->fresh()->transfer_group_id)->toBe($group);
    expect($u2->fresh()->transfer_group_id)->toBe($group);
    expect($u3->fresh()->transfer_group_id)->toBe($group);
    expect($rimborso->fresh()->isTransfer())->toBeTrue();
});

it('flags a partita di giro whose amounts do not net to zero', function () {
    $this->actingAs(owner());
    $inbank = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $vivid = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $uscita = BankTransaction::create(['bank_account_id' => $inbank->id, 'booked_at' => '2026-06-29', 'amount' => -58, 'direction' => 'out', 'description' => 'POS', 'dedup_hash' => 'x1']);
    $entrata = BankTransaction::create(['bank_account_id' => $vivid->id, 'booked_at' => '2026-07-14', 'amount' => 279, 'direction' => 'in', 'description' => 'rimborso', 'dedup_hash' => 'x2']);

    // Manca il resto delle uscite: la somma non torna → proposta con sbilancio (non blocca ma segnala).
    $res = app(ProposeTransferTool::class)->run(['movement_id' => $entrata->id, 'twin_ids' => [$uscita->id]]);
    expect($res->isError)->toBeFalse();
    expect($res->action['net'])->toBe(221.0);
});

it('refuses to propose a cost for an entrata or a transfer', function () {
    $this->actingAs(owner());
    $account = BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid']);
    $in = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-07-06', 'amount' => 100, 'direction' => 'in', 'description' => 'incasso', 'dedup_hash' => 'e1']);

    $res = app(ProposeCostTool::class)->run(['movement_id' => $in->id, 'category' => 'Trasferte']);
    expect($res->isError)->toBeTrue();
    expect($res->action)->toBeNull();
});
