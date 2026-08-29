<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Cattura quello che l'assistente manda davvero all'API. */
function spiaChat(): object
{
    $spia = new class
    {
        public ?string $systemStatic = null;

        /** @var array<int, array<string, mixed>> */
        public array $messages = [];
    };

    app()->instance(ChatClient::class, new class($spia) implements ChatClient
    {
        public function __construct(private readonly object $spia) {}

        public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
        {
            $this->spia->systemStatic = $systemStatic;
            $this->spia->messages = $messages;

            return new AssistantTurn([], [], 'Fatto.', 'end_turn');
        }
    });

    return $spia;
}

function threadConMessaggi(int $quanti): AssistantThread
{
    $thread = AssistantThread::create(['user_id' => User::factory()->admin()->create()->id, 'title' => 'Prova']);

    for ($i = 0; $i < $quanti; $i++) {
        AssistantMessage::create([
            'assistant_thread_id' => $thread->id,
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => 'Messaggio numero '.$i,
        ]);
    }

    return $thread->fresh();
}

it('marca la fine della conversazione come punto di taglio della cache', function () {
    $spia = spiaChat();

    app(AssistantRunner::class)->run(threadConMessaggi(5));

    $ultimo = end($spia->messages);
    $blocchi = $ultimo['content'];

    expect($blocchi)->toBeArray();
    expect(end($blocchi)['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h']);
});

it('mette un solo punto di taglio, non uno per messaggio', function () {
    // Ogni marcatore è una cache da scrivere: metterne uno per messaggio
    // costerebbe più di quanto fa risparmiare (e il massimo è 4).
    $spia = spiaChat();

    app(AssistantRunner::class)->run(threadConMessaggi(6));

    $marcatori = collect($spia->messages)
        ->flatMap(fn (array $m): array => is_array($m['content']) ? $m['content'] : [])
        ->filter(fn ($blocco): bool => is_array($blocco) && isset($blocco['cache_control']))
        ->count();

    expect($marcatori)->toBe(1);
});

it('non si rompe su una conversazione vuota', function () {
    $spia = spiaChat();
    $thread = AssistantThread::create(['user_id' => User::factory()->admin()->create()->id, 'title' => 'Vuoto']);

    $esito = app(AssistantRunner::class)->run($thread);

    expect($esito['content'])->toBe('Fatto.');
});
