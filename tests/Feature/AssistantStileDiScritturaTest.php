<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Models\AssistantThread;
use App\Models\User;
use App\Support\StileDiScrittura;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Lo stile non è un'impostazione: deve stare nella parte statica del prompt,
// così vale per ogni utente e resta nel prefisso in cache.
it('mette lo stile di scrittura nella parte statica del prompt', function () {
    $spia = new class
    {
        public ?string $systemStatic = null;
    };

    app()->instance(ChatClient::class, new class($spia) implements ChatClient
    {
        public function __construct(private readonly object $spia) {}

        public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
        {
            $this->spia->systemStatic = $systemStatic;

            return new AssistantTurn([], [], 'Fatto.', 'end_turn');
        }
    });

    $thread = AssistantThread::create(['user_id' => User::factory()->admin()->create()->id, 'title' => 'Stile']);

    app(AssistantRunner::class)->run($thread);

    expect($spia->systemStatic)->toContain(StileDiScrittura::blocco());
});
