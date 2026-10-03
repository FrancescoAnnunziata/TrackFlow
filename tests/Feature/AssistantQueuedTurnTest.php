<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Filament\Pages\AssistenteAi;
use App\Jobs\RunAssistantTurnJob;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Il turno dell'assistente gira in coda, non dentro la richiesta web: send()
 * deve tornare subito lasciando un segnaposto, e la pagina smette di
 * interrogare solo quando quel segnaposto è stato chiuso — in un modo o
 * nell'altro, perché un segnaposto che resta "in lavorazione" è una pagina che
 * gira a vuoto per sempre.
 */
class ChatClientPerLaCoda implements ChatClient
{
    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        return new AssistantTurn(
            [['type' => 'text', 'text' => 'A luglio hai fatturato 4.000 €.']],
            [],
            'A luglio hai fatturato 4.000 €.',
            'end_turn',
        );
    }
}

function threadDi(User $user): AssistantThread
{
    return AssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Prova',
        'model' => 'claude-opus-5',
    ]);
}

it('send mette il turno in coda e non chiama il modello nella richiesta web', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    threadDi($admin);

    Livewire::actingAs($admin)
        ->test(AssistenteAi::class)
        ->set('draft', 'quanto ho fatturato a luglio?')
        ->call('send')
        ->assertOk();

    Queue::assertPushed(RunAssistantTurnJob::class, 1);

    // La domanda è registrata e la risposta è un segnaposto in lavorazione.
    expect(AssistantMessage::where('role', 'user')->value('content'))->toBe('quanto ho fatturato a luglio?')
        ->and(AssistantMessage::where('role', 'assistant')->value('status'))->toBe('pending');
});

it('il segnaposto in lavorazione tiene accesa l attesa e non finisce nel prompt', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);
    AssistantMessage::create([
        'assistant_thread_id' => $thread->id,
        'role' => 'assistant',
        'content' => '',
        'status' => 'pending',
    ]);

    $componente = Livewire::actingAs($admin)->test(AssistenteAi::class);

    expect($componente->instance()->awaitingReply)->toBeTrue();

    // history() passa al modello solo i messaggi 'done': il segnaposto no.
    $storia = (new ReflectionClass(AssistantRunner::class))->getMethod('history');
    $storia->setAccessible(true);
    $passati = $storia->invoke(app(AssistantRunner::class), $thread->fresh());
    expect($passati)->toBeEmpty();
});

it('il job riempie il segnaposto e spegne l attesa', function () {
    app()->instance(ChatClient::class, new ChatClientPerLaCoda);

    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);
    AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'user',
        'content' => 'quanto ho fatturato a luglio?', 'status' => 'done',
    ]);
    $placeholder = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant',
        'content' => '', 'status' => 'pending',
    ]);

    (new RunAssistantTurnJob($thread->id, $placeholder->id))->handle(app(AssistantRunner::class));

    expect($placeholder->fresh()->status)->toBe('done')
        ->and($placeholder->fresh()->content)->toBe('A luglio hai fatturato 4.000 €.');

    $componente = Livewire::actingAs($admin)->test(AssistenteAi::class);
    expect($componente->instance()->awaitingReply)->toBeFalse();
});

it('attribuisce il costo AI all utente del thread anche senza nessuno collegato', function () {
    app()->instance(ChatClient::class, new ChatClientPerLaCoda);

    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);
    $placeholder = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant',
        'content' => '', 'status' => 'pending',
    ]);

    // Nella coda non c'è sessione: nessun actingAs, come in produzione.
    (new RunAssistantTurnJob($thread->id, $placeholder->id))->handle(app(AssistantRunner::class));

    expect(auth()->id())->toBe($admin->id);
});

it('un job morto chiude il segnaposto invece di lasciare la pagina a girare', function () {
    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);
    $placeholder = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant',
        'content' => '', 'status' => 'pending',
    ]);

    (new RunAssistantTurnJob($thread->id, $placeholder->id))
        ->failed(new RuntimeException('timeout del worker'));

    expect($placeholder->fresh()->status)->toBe('failed')
        ->and($placeholder->fresh()->content)->toContain('timeout del worker');

    $componente = Livewire::actingAs($admin)->test(AssistenteAi::class);
    expect($componente->instance()->awaitingReply)->toBeFalse();
});

it('chiude l attesa quando la risposta non puo piu arrivare', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);

    // Segnaposto nato 20 minuti fa: oltre il timeout del job più il margine.
    // È il caso "il worker non sta girando", che altrimenti fa girare la pagina
    // a vuoto per sempre senza dire niente a nessuno.
    $vecchio = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant',
        'content' => '', 'status' => 'pending',
    ]);
    $vecchio->forceFill(['created_at' => now()->subMinutes(20)])->save();

    $componente = Livewire::actingAs($admin)->test(AssistenteAi::class);
    expect($componente->instance()->awaitingReply)->toBeTrue();

    $componente->call('checkReply');

    expect($vecchio->fresh()->status)->toBe('failed')
        ->and($vecchio->fresh()->content)->toContain('troppo a lungo')
        ->and($componente->instance()->awaitingReply)->toBeFalse();
});

it('non tocca un attesa ancora legittima', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $thread = threadDi($admin);
    $fresco = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant',
        'content' => '', 'status' => 'pending',
    ]);

    Livewire::actingAs($admin)->test(AssistenteAi::class)->call('checkReply');

    expect($fresco->fresh()->status)->toBe('pending');
});
