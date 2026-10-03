<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Come finisce un turno conta quanto il contenuto: una risposta tagliata dal
 * tetto di token, o rifiutata dal modello, non deve arrivare a chi legge con
 * l'aspetto di una risposta completa.
 */
class ChatClientConEsito implements ChatClient
{
    public function __construct(private string $stopReason, private string $text) {}

    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        return new AssistantTurn(
            [['type' => 'text', 'text' => $this->text]],
            [],
            $this->text,
            $this->stopReason,
        );
    }
}

function turnoCon(string $stopReason, string $text = ''): array
{
    app()->instance(ChatClient::class, new ChatClientConEsito($stopReason, $text));

    $user = User::factory()->admin()->create();
    $thread = AssistantThread::create([
        'user_id' => $user->id,
        'title' => 'Prova',
        'model' => 'claude-opus-5',
    ]);
    AssistantMessage::create([
        'assistant_thread_id' => $thread->id,
        'role' => 'user',
        'content' => 'quanto ho fatturato a luglio?',
        'status' => 'done',
    ]);

    return app(AssistantRunner::class)->run($thread->fresh());
}

it('avvisa che la risposta e incompleta quando il modello sbatte sul tetto di token', function () {
    $esito = turnoCon('max_tokens', 'A luglio le fatture emesse sono');

    expect($esito['content'])
        ->toContain('A luglio le fatture emesse sono')
        ->toContain('interrotta')
        ->toContain('incompleto');
});

it('non spaccia un rifiuto per una risposta riuscita', function () {
    $esito = turnoCon('refusal');

    expect($esito['content'])->toBe('Non posso rispondere a questa richiesta.')
        ->and($esito['content'])->not->toBe('Fatto.');
});

it('lascia intatta una risposta che finisce normalmente', function () {
    $esito = turnoCon('end_turn', 'A luglio hai fatturato 4.000 €.');

    expect($esito['content'])->toBe('A luglio hai fatturato 4.000 €.');
});

it('tiene il fallback quando il turno finisce senza testo', function () {
    $esito = turnoCon('end_turn');

    expect($esito['content'])->toBe('Fatto.');
});
