<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Filament\Pages\AssistenteAi;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use App\Support\ManualeOperativo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('riduce il manuale a testo leggibile, senza HTML né CSS', function () {
    $testo = app(ManualeOperativo::class)->testo();

    expect($testo)->not->toContain('<div')
        ->and($testo)->not->toContain('g-callout')
        ->and($testo)->not->toContain('&egrave;')
        // Le procedure che l'assistente deve conoscere.
        ->and($testo)->toContain('Fatture estere')
        ->and($testo)->toContain('Alsea')
        ->and($testo)->toContain('Fiscozen');
});

it('mette il manuale nel prompt statico, quello che resta in cache', function () {
    // Il manuale è identico a ogni chiamata: deve stare nel blocco cachato,
    // non nel contesto per-thread, o si paga pieno a ogni messaggio.
    $catturato = null;

    app()->instance(ChatClient::class, new class($catturato) implements ChatClient
    {
        public function __construct(public ?string &$statico) {}

        public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
        {
            $this->statico = $systemStatic;

            return new AssistantTurn([], [], 'Fatto.', 'end_turn');
        }
    });

    $utente = User::factory()->admin()->create();
    $thread = AssistantThread::create(['user_id' => $utente->id, 'title' => 'Prova']);
    AssistantMessage::create(['assistant_thread_id' => $thread->id, 'role' => 'user', 'content' => 'Come carico una fattura estera?']);

    app(AssistantRunner::class)->run($thread->fresh());

    $statico = app(ChatClient::class)->statico;

    expect($statico)->toContain('MANUALE OPERATIVO DI TRACKFLOW')
        ->and($statico)->toContain('Fatture estere')
        ->and($statico)->toContain('Importo EUR');
});

it('apre la chat a chi tiene la contabilità e la tiene chiusa ai clienti', function () {
    // Paola è admin: prima la pagina era legata all'indirizzo email del titolare.
    $this->actingAs(User::factory()->admin()->create(['email' => 'paola@esempio.it']));
    expect(AssistenteAi::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'accountant']));
    expect(AssistenteAi::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'member']));
    expect(AssistenteAi::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => 'client']));
    expect(AssistenteAi::canAccess())->toBeFalse();
});

it('non fa vedere a un admin le conversazioni di un altro', function () {
    $giorgio = User::factory()->admin()->create();
    $paola = User::factory()->admin()->create();

    AssistantThread::create(['user_id' => $giorgio->id, 'title' => 'Chat di Giorgio']);

    $this->actingAs($paola);
    $pagina = new AssistenteAi;
    $pagina->mount();

    expect($pagina->threadId)->toBeNull();
});
