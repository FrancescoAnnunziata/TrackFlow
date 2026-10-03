<?php

namespace App\Jobs;

use App\Assistant\AssistantRunner;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Esegue un turno dell'assistente in background e scrive la risposta nel
 * messaggio segnaposto che la pagina sta già mostrando come "in lavorazione".
 *
 * Prima il turno girava dentro l'azione send(), cioè dentro la richiesta web:
 * un turno lungo (ragionamento + fino a 8 passaggi di tool) sfondava il timeout
 * di PHP-FPM e il browser restava appeso senza risposta né errore. In coda il
 * tempo non è più un limite della richiesta, e la pagina interroga il
 * segnaposto in polling — lo stesso schema delle estrazioni PDF.
 */
class RunAssistantTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un turno può pensare a lungo e fare più giri di tool: margine ampio, ma
     * finito, perché oltre un certo punto è più utile dire che è andata male.
     */
    public int $timeout = 600;

    /**
     * Un solo tentativo: ogni ritentativo è un'altra chiamata a pagamento, e un
     * turno a metà non è idempotente (può aver già scritto proposte).
     */
    public int $tries = 1;

    public function __construct(
        private readonly int $threadId,
        private readonly int $placeholderId,
    ) {}

    public function handle(AssistantRunner $runner): void
    {
        $thread = AssistantThread::find($this->threadId);
        $placeholder = AssistantMessage::find($this->placeholderId);

        if ($thread === null || $placeholder === null) {
            return; // conversazione o segnaposto cancellati nel frattempo
        }

        // Nella coda non c'è nessuno collegato, e AiUsageRecorder attribuisce il
        // costo con auth()->id(): senza questo, ogni chiamata dell'assistente
        // risulterebbe di nessuno e il conto per utente perderebbe i pezzi.
        Auth::onceUsingId($thread->user_id);

        // I link ai documenti li costruiscono i tool con Resource::getUrl().
        // Senza richiesta al pannello Filament ricade su quello predefinito e il
        // link esce giusto comunque (c'è un test che lo verifica): lo dichiariamo
        // qui perché il giorno che nasce un secondo pannello la scelta non sia
        // implicita, e perché la coda è l'unico posto dove nessuno l'ha scelta.
        Filament::setCurrentPanel(Filament::getPanel('app'));

        try {
            $result = $runner->run($thread);

            $placeholder->update([
                'content' => $result['content'],
                'status' => 'done',
                'steps' => $result['steps'] ?: null,
                'actions' => $result['actions'] ?: null,
            ]);
        } catch (Throwable $e) {
            $placeholder->update([
                'content' => 'Errore: '.$e->getMessage(),
                'status' => 'failed',
            ]);
        }
    }

    /**
     * Se il job muore per davvero (timeout, worker riavviato, coda in panne) il
     * segnaposto resterebbe "in lavorazione" per sempre e la pagina lo
     * interrogherebbe all'infinito: va chiuso comunque.
     */
    public function failed(?Throwable $e): void
    {
        AssistantMessage::where('id', $this->placeholderId)
            ->where('status', 'pending')
            ->update([
                'content' => 'La richiesta non è andata a buon fine'
                    .($e !== null ? ': '.$e->getMessage() : '.')
                    .' Riprova, oppure riformula la domanda in modo più stretto.',
                'status' => 'failed',
            ]);
    }
}
