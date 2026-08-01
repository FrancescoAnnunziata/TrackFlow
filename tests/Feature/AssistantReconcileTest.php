<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Assistant\Tools\ProposeReconciliationTool;
use App\Filament\Pages\AssistenteAi;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reconciliation\MovementReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Fake che restituisce turni pre-scriptati, così il runner non chiama l'API. */
class FakeChatClient implements ChatClient
{
    /** @param array<int, AssistantTurn> $turns */
    public function __construct(private array $turns) {}

    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        return array_shift($this->turns) ?? new AssistantTurn([['type' => 'text', 'text' => 'Fine.']], [], 'Fine.', 'end_turn');
    }
}

function telepassScenario(): array
{
    $supplier = Supplier::create(['name' => 'Telepass SpA', 'vat_number' => 'IT09771701001']);
    $a = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'T-A', 'type' => 'expense',
        'document_date' => '2026-07-23', 'amount_net' => 2.60, 'amount_vat' => 0, 'amount_gross' => 2.60,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $b = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'T-B', 'type' => 'expense',
        'document_date' => '2026-07-23', 'amount_net' => 2.93, 'amount_vat' => 0, 'amount_gross' => 2.93,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-07-23', 'amount' => -5.53,
        'direction' => 'out', 'description' => 'ADDEBITO SDD TELEPASS SPA', 'dedup_hash' => 'aitp1',
    ]);

    return [$tx, $a, $b];
}

it('proposes a reconciliation via the tool loop, then applies it on confirm', function () {
    [$tx, $a, $b] = telepassScenario();

    $input = [
        'movement_id' => $tx->id,
        'targets' => [
            ['type' => 'passive_invoice', 'id' => $a->id],
            ['type' => 'passive_invoice', 'id' => $b->id],
        ],
    ];

    app()->instance(ChatClient::class, new FakeChatClient([
        new AssistantTurn(
            [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_reconciliation', 'input' => $input]],
            [['id' => 'tu_1', 'name' => 'propose_reconciliation', 'input' => $input]],
            '', 'tool_use',
        ),
        new AssistantTurn([['type' => 'text', 'text' => 'Proposta pronta, confermala.']], [], 'Proposta pronta, confermala.', 'end_turn'),
    ]));

    $thread = AssistantThread::create(['user_id' => User::factory()->admin()->create()->id, 'model' => 'claude-opus-4-8']);
    AssistantMessage::create(['assistant_thread_id' => $thread->id, 'role' => 'user', 'content' => 'riconcilia il telepass', 'status' => 'done']);

    $result = app(AssistantRunner::class)->run($thread->fresh());

    // La proposta è stata registrata (ma NON scritta a DB).
    expect($result['actions'])->toHaveCount(1);
    $action = $result['actions'][0];
    expect($action['type'])->toBe('reconcile');
    expect($action['total'])->toBe(5.53);
    expect($tx->fresh()->reconciled)->toBeFalse();

    // Conferma → applica.
    $outcome = app(MovementReconciler::class)->reconcile($tx->fresh(), $action['targets']);

    expect($outcome['attached'])->toBe(2);
    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($a->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
    expect($b->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
});

it('refuses to propose reconciling an already-reconciled movement', function () {
    [$tx, $a, $b] = telepassScenario();

    // Riconcilia già tutto.
    app(MovementReconciler::class)->reconcile($tx, [
        ['type' => 'passive_invoice', 'id' => $a->id],
        ['type' => 'passive_invoice', 'id' => $b->id],
    ]);
    expect($tx->fresh()->reconciled)->toBeTrue();

    $res = app(ProposeReconciliationTool::class)->run([
        'movement_id' => $tx->id,
        'targets' => [['type' => 'passive_invoice', 'id' => $a->id]],
    ]);

    expect($res->isError)->toBeTrue();
    expect($res->action)->toBeNull();
});

it('drives the page: send proposes, confirm reconciles', function () {
    [$tx, $a, $b] = telepassScenario();
    // La pagina è privata al titolare: il test agisce come lui.
    $admin = User::factory()->admin()->create(['email' => 'giorgio.giotto@g8labs.it']);
    $this->actingAs($admin);

    $input = [
        'movement_id' => $tx->id,
        'targets' => [
            ['type' => 'passive_invoice', 'id' => $a->id],
            ['type' => 'passive_invoice', 'id' => $b->id],
        ],
    ];
    app()->instance(ChatClient::class, new FakeChatClient([
        new AssistantTurn(
            [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_reconciliation', 'input' => $input]],
            [['id' => 'tu_1', 'name' => 'propose_reconciliation', 'input' => $input]],
            '', 'tool_use',
        ),
        new AssistantTurn([['type' => 'text', 'text' => 'Confermala.']], [], 'Confermala.', 'end_turn'),
    ]));

    $page = Livewire\Livewire::test(AssistenteAi::class)
        ->set('draft', 'riconcilia il telepass')
        ->call('send');

    $assistantMsg = AssistantMessage::where('role', 'assistant')->latest('id')->first();
    expect($assistantMsg)->not->toBeNull();
    $action = ($assistantMsg->actions ?? [])[0] ?? null;
    expect($action['status'] ?? null)->toBe('pending');

    $page->call('confirmProposal', $assistantMsg->id, $action['id']);

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($a->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
    expect(AssistantMessage::find($assistantMsg->id)->actions[0]['status'])->toBe('applied');
});
