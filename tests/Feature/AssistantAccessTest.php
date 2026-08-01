<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Assistant\Tools\ReadInvoiceExpensesTool;
use App\Filament\Pages\AssistenteAi;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Fake che cattura gli strumenti passati al modello, per verificarne l'elenco. */
class CapturingChatClient implements ChatClient
{
    public array $lastTools = [];

    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        $this->lastTools = $tools;

        return new AssistantTurn([['type' => 'text', 'text' => 'ok']], [], 'ok', 'end_turn');
    }
}

function toolNamesFor(User $user): array
{
    $fake = new CapturingChatClient([]);
    app()->instance(ChatClient::class, $fake);

    $thread = AssistantThread::create(['user_id' => $user->id, 'model' => 'claude-opus-4-8']);
    AssistantMessage::create(['assistant_thread_id' => $thread->id, 'role' => 'user', 'content' => 'ciao', 'status' => 'done']);

    app(AssistantRunner::class)->run($thread->fresh());

    return collect($fake->lastTools)->pluck('name')->all();
}

it('exposes the reconcile tool to admins but not to accountants', function () {
    $admin = User::factory()->admin()->create();
    $accountant = User::factory()->create(['role' => 'accountant']);

    $adminTools = toolNamesFor($admin);
    $accountantTools = toolNamesFor($accountant);

    expect($adminTools)->toContain('propose_reconciliation');
    expect($accountantTools)->not->toContain('propose_reconciliation');

    // Entrambi hanno i tool di lettura, incluso il dettaglio rimborsi.
    expect($accountantTools)->toContain('read_bank_movements')
        ->toContain('read_invoice_reimbursed_expenses');
});

it('lets the accountant page load read-only, blocks confirm actions', function () {
    $accountant = User::factory()->create(['role' => 'accountant']);

    expect(AssistenteAi::canAccess())->toBeFalse(); // nessun utente loggato
    $this->actingAs($accountant);
    expect(AssistenteAi::canAccess())->toBeTrue();

    // Un client non entra.
    $this->actingAs(User::factory()->create(['role' => 'client']));
    expect(AssistenteAi::canAccess())->toBeFalse();
});

it('reads the reimbursed expenses of an invoice and their linked passive invoices', function () {
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'FIORAVANTI S.R.L.']);
    $supplier = Supplier::create(['name' => 'Tekworld S.r.l.']);

    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => '781T', 'type' => 'expense',
        'document_date' => '2026-04-03', 'amount_net' => 158.91, 'amount_vat' => 34.96, 'amount_gross' => 193.87,
        'payment_status' => PassiveInvoice::STATUS_PAID,
    ]);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '11/2026',
        'issue_date' => '2026-04-18', 'period_from' => '2026-04-01', 'period_to' => '2026-04-30',
        'vat_rate' => 22, 'status' => 'sent',
    ]);
    $expense = Expense::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'supplier_id' => $supplier->id,
        'passive_invoice_id' => $passive->id, 'date' => '2026-04-03', 'amount' => 193.87, 'conto' => 'Acquisto materiale e macchinari',
    ]);
    $invoice->expenses()->attach($expense->id);

    $res = app(ReadInvoiceExpensesTool::class)->run(['invoice_id' => $invoice->id]);

    expect($res->isError)->toBeFalse();
    expect($res->content)->toContain('781T')          // la fattura passiva collegata
        ->toContain('Tekworld')                        // il fornitore
        ->toContain('193,87');                         // l'importo
});
