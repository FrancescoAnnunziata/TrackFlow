<?php

namespace App\Filament\Pages;

use App\Assistant\AssistantRunner;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\BankTransaction;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Reconciliation\MovementActions;
use App\Services\Reconciliation\MovementReconciler;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Assistente AI (chat con Claude, con strumenti): legge movimenti e fatture e
 * propone riconciliazioni che l'utente conferma. Solo admin. Il turno gira in
 * modo sincrono nell'azione send() (nessun worker richiesto).
 */
class AssistenteAi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $title = 'Assistente AI';

    protected static ?string $navigationLabel = 'Assistente AI';

    protected static ?int $navigationSort = -9;

    protected string $view = 'filament.pages.assistente-ai';

    public ?int $threadId = null;

    public string $draft = '';

    /** La chat è privata al titolare; il commercialista (ruolo accountant) la usa in sola lettura. */
    private const OWNER_EMAIL = 'giorgio.giotto@g8labs.it';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->email === self::OWNER_EMAIL || $user->isAccountant());
    }

    /** Chi può eseguire azioni (confermare riconciliazioni): solo gli admin. */
    private function canReconcile(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->threadId = AssistantThread::where('user_id', auth()->id())->latest('id')->value('id');
    }

    /** @return Collection<int, AssistantThread> */
    public function getThreadsProperty(): Collection
    {
        return AssistantThread::where('user_id', auth()->id())->latest('id')->limit(30)->get();
    }

    /** Costo AI totale del mese (USD), tutte le funzioni AI incluse. */
    public function getMonthlyCostProperty(): float
    {
        return app(AiUsageRecorder::class)->monthlyCost();
    }

    /** @return Collection<int, AssistantMessage> */
    public function getMessagesProperty(): Collection
    {
        if ($this->threadId === null) {
            return collect();
        }

        return AssistantMessage::where('assistant_thread_id', $this->threadId)->orderBy('id')->get();
    }

    public function newChat(): void
    {
        $this->threadId = null;
        $this->draft = '';
    }

    public function openThread(int $id): void
    {
        $this->threadId = $id;
    }

    public function send(): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            return;
        }

        $thread = $this->threadId
            ? AssistantThread::find($this->threadId)
            : AssistantThread::create([
                'user_id' => auth()->id(),
                'title' => Str::limit($text, 40),
                'model' => (string) config('services.anthropic.model', 'claude-opus-4-8'),
            ]);

        if ($thread === null) {
            return;
        }
        $this->threadId = $thread->id;

        AssistantMessage::create([
            'assistant_thread_id' => $thread->id,
            'role' => 'user',
            'content' => $text,
            'status' => 'done',
        ]);

        $this->draft = '';

        try {
            $result = app(AssistantRunner::class)->run($thread->fresh());
            AssistantMessage::create([
                'assistant_thread_id' => $thread->id,
                'role' => 'assistant',
                'content' => $result['content'],
                'status' => 'done',
                'steps' => $result['steps'] ?: null,
                'actions' => $result['actions'] ?: null,
            ]);
        } catch (Throwable $e) {
            AssistantMessage::create([
                'assistant_thread_id' => $thread->id,
                'role' => 'assistant',
                'content' => 'Errore: '.$e->getMessage(),
                'status' => 'failed',
            ]);
        }
    }

    public function confirmProposal(int $messageId, string $actionId): void
    {
        if (! $this->canReconcile()) {
            return;
        }

        $message = AssistantMessage::find($messageId);
        if ($message === null || (int) $message->assistant_thread_id !== (int) $this->threadId) {
            return;
        }

        $actions = $message->actions ?? [];
        $idx = collect($actions)->search(fn (array $a): bool => ($a['id'] ?? null) === $actionId);
        if ($idx === false || ($actions[$idx]['status'] ?? null) !== 'pending') {
            return;
        }

        $action = $actions[$idx];

        $apply = function (string $title, ?string $body = null) use (&$actions, $idx, $message): void {
            $actions[$idx]['status'] = 'applied';
            $actions[$idx]['applied_at'] = now()->toDateTimeString();
            $message->actions = $actions;
            $message->save();
            Notification::make()->success()->title($title)->body($body)->send();
        };
        $void = function (string $title, string $body) use (&$actions, $idx, $message): void {
            $actions[$idx]['status'] = 'cancelled';
            $message->actions = $actions;
            $message->save();
            Notification::make()->warning()->title($title)->body($body)->send();
        };

        try {
            // Giroconto: collega i due movimenti gemelli.
            if (($action['type'] ?? '') === 'transfer') {
                $tx = BankTransaction::find($action['movement_id'] ?? 0);
                $twin = BankTransaction::find($action['twin_id'] ?? 0);
                if ($tx === null || $twin === null) {
                    Notification::make()->danger()->title('Movimento non trovato')->send();

                    return;
                }
                if ($tx->isTransfer() || $twin->isTransfer() || $tx->reconciled || $twin->reconciled) {
                    $void('Non più applicabile', 'Uno dei due movimenti risulta già riconciliato o giroconto.');

                    return;
                }
                app(MovementActions::class)->markAsTransfer($tx, $twin);
                $apply('Segnato come giroconto');

                return;
            }

            $tx = BankTransaction::find($action['movement_id'] ?? 0);
            if ($tx === null) {
                Notification::make()->danger()->title('Movimento non trovato')->send();

                return;
            }

            // Costo: crea il costo dal movimento e lo riconcilia.
            if (($action['type'] ?? '') === 'cost') {
                if ($tx->direction !== BankTransaction::DIRECTION_OUT || $tx->isTransfer()) {
                    $void('Non applicabile', 'Il movimento non è più un\'uscita valida per un costo.');

                    return;
                }
                if ($tx->unreconciledAmount() <= 0.01) {
                    $void('Già riconciliato', 'Nel frattempo il movimento risulta già riconciliato.');

                    return;
                }
                app(MovementActions::class)->markAsCost(
                    $tx,
                    (string) ($action['description'] ?? ''),
                    $action['category'] ?? null,
                    null,
                    (float) ($action['amount'] ?? $tx->unreconciledAmount()),
                );
                $apply('Costo creato e riconciliato', $action['category'] ? 'Categoria: '.$action['category'] : null);

                return;
            }

            // Riconciliazione verso documenti (default).
            if ($tx->unreconciledAmount() <= 0.01) {
                $void('Movimento già riconciliato', 'Nel frattempo il movimento risulta già riconciliato: proposta annullata.');

                return;
            }
            $outcome = app(MovementReconciler::class)->reconcile($tx, $action['targets'] ?? []);
            if ($outcome['attached'] === 0) {
                $void('Niente da riconciliare', 'I documenti indicati risultano già riconciliati o pagati.');

                return;
            }
            $apply('Riconciliazione applicata', "Agganciati {$outcome['attached']} documenti.".($outcome['remaining'] > 0.01 ? ' Residuo € '.number_format($outcome['remaining'], 2, ',', '.') : ''));
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Azione non riuscita')->body($e->getMessage())->send();
        }
    }

    public function cancelProposal(int $messageId, string $actionId): void
    {
        $message = AssistantMessage::find($messageId);
        if ($message === null || (int) $message->assistant_thread_id !== (int) $this->threadId) {
            return;
        }

        $actions = $message->actions ?? [];
        $idx = collect($actions)->search(fn (array $a): bool => ($a['id'] ?? null) === $actionId);
        if ($idx === false || ($actions[$idx]['status'] ?? null) !== 'pending') {
            return;
        }

        $actions[$idx]['status'] = 'cancelled';
        $message->actions = $actions;
        $message->save();
    }
}
