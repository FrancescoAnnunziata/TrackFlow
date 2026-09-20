<?php

use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Filament\Pages\AssetScan;
use App\Filament\Pages\AssistenteAi;
use App\Filament\Pages\FattureEmessePdf;
use App\Filament\Pages\FattureEstere;
use App\Filament\Pages\RiconciliazioniAttive;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Le proprietà pubbliche che portano stato deciso dal server (id del record su
 * cui si lavora, chiavi di cache, flag) sono manomettibili dal client: basta un
 * update su /livewire/update. Questi test tengono fermi i due lati della
 * correzione: #[Locked] rifiuta l'update, e l'autorizzazione viene comunque
 * rifatta dentro l'azione, perché #[Locked] non protegge da un id legittimo
 * arrivato per un'altra strada.
 */

/**
 * Pagine gemelle delle vere, che partono già con lo stato manomesso: #[Locked]
 * blocca il payload, quindi è l'unico modo di provare i controlli *dentro* le
 * azioni. Il valore iniettato sopravvive al giro successivo perché finisce nello
 * snapshot, esattamente come se ci fosse arrivato per una via legittima.
 */
class AssistenteAiConIdAltrui extends AssistenteAi
{
    public static ?int $idIniettato = null;

    public function mount(): void
    {
        $this->threadId = static::$idIniettato;
    }
}

class FattureEstereConChiaveAltrui extends FattureEstere
{
    public static string $chiaveIniettata = '';

    public function mount(): void
    {
        parent::mount();
        $this->extractKey = static::$chiaveIniettata;
        $this->extracting = true;
    }
}

/** Fake del client di chat: send() non deve chiamare l'API vera. */
class ChatClientMuto implements ChatClient
{
    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        return new AssistantTurn([['type' => 'text', 'text' => 'ok']], [], 'ok', 'end_turn');
    }
}

/** Thread altrui, con un messaggio dentro, per provare gli accessi incrociati. */
function threadAltrui(string $testo = 'segreto contabile'): AssistantThread
{
    $altro = User::factory()->admin()->create();
    $thread = AssistantThread::create(['user_id' => $altro->id, 'title' => 'Chat altrui', 'model' => 'claude-opus-5']);
    AssistantMessage::create([
        'assistant_thread_id' => $thread->id,
        'role' => 'assistant',
        'content' => $testo,
        'status' => 'done',
    ]);

    return $thread;
}

it('rifiuta un update del payload su threadId', function () {
    $admin = User::factory()->admin()->create();
    $altrui = threadAltrui();

    Livewire::actingAs($admin)
        ->test(AssistenteAi::class)
        ->set('threadId', $altrui->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('non apre il thread di un altro utente da openThread', function () {
    $admin = User::factory()->admin()->create();
    $altrui = threadAltrui();

    Livewire::actingAs($admin)
        ->test(AssistenteAi::class)
        ->call('openThread', $altrui->id)
        ->assertForbidden();
});

it('non mostra i messaggi di un thread altrui nemmeno con l id gia dentro', function () {
    $admin = User::factory()->admin()->create();
    AssistenteAiConIdAltrui::$idIniettato = threadAltrui('segreto contabile')->id;

    Livewire::actingAs($admin)
        ->test(AssistenteAiConIdAltrui::class)
        ->assertDontSee('segreto contabile');
});

it('non scrive nel thread di un altro utente', function () {
    app()->instance(ChatClient::class, new ChatClientMuto);

    $admin = User::factory()->admin()->create();
    $altrui = threadAltrui();
    AssistenteAiConIdAltrui::$idIniettato = $altrui->id;

    Livewire::actingAs($admin)
        ->test(AssistenteAiConIdAltrui::class)
        ->set('draft', 'ciao')
        ->call('send');

    // Solo il messaggio creato dalla fixture: niente domanda, niente risposta.
    expect(AssistantMessage::where('assistant_thread_id', $altrui->id)->count())->toBe(1);
});

it('non conferma una proposta che sta nel thread di un altro utente', function () {
    $admin = User::factory()->admin()->create();
    $altrui = threadAltrui();
    AssistenteAiConIdAltrui::$idIniettato = $altrui->id;

    $messaggio = AssistantMessage::create([
        'assistant_thread_id' => $altrui->id,
        'role' => 'assistant',
        'content' => 'proposta',
        'status' => 'done',
        'actions' => [['id' => 'a1', 'type' => 'cost', 'status' => 'pending', 'movement_id' => 1]],
    ]);

    Livewire::actingAs($admin)
        ->test(AssistenteAiConIdAltrui::class)
        ->call('confirmProposal', $messaggio->id, 'a1');

    expect($messaggio->fresh()->actions[0]['status'])->toBe('pending');
});

it('rifiuta un update del payload sulle chiavi di estrazione', function (string $pagina, string $proprieta, mixed $valore) {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test($pagina)
        ->set($proprieta, $valore);
})->throws(CannotUpdateLockedPropertyException::class)->with([
    [FattureEmessePdf::class, 'extractKey', 'emesse-extract:999:rubata'],
    [FattureEmessePdf::class, 'extracting', true],
    [FattureEstere::class, 'extractKey', 'estere-extract:999:rubata'],
    [FattureEstere::class, 'extracting', true],
]);

it('non legge ne cancella una chiave di cache fuori dallo spazio dell utente', function () {
    $admin = User::factory()->admin()->create();

    FattureEstereConChiaveAltrui::$chiaveIniettata = 'estere-extract:999:altrui';
    Cache::put('estere-extract:999:altrui', [
        'status' => 'done',
        'rows' => [['supplier_name' => 'FORNITORE ALTRUI', 'document_date' => '2026-01-01']],
    ], now()->addHour());

    Livewire::actingAs($admin)
        ->test(FattureEstereConChiaveAltrui::class)
        ->call('checkExtraction')
        ->assertDontSee('FORNITORE ALTRUI');

    // E la chiave di un altro non viene nemmeno cancellata.
    expect(Cache::get('estere-extract:999:altrui'))->not->toBeNull();
});

it('rifiuta un update del payload sulle proprieta di sola presentazione', function (string $pagina, string $proprieta, mixed $valore) {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test($pagina)
        ->set($proprieta, $valore);
})->throws(CannotUpdateLockedPropertyException::class)->with([
    [RiconciliazioniAttive::class, 'emptyMessage', '<script>alert(1)</script>'],
    [AssetScan::class, 'notFound', true],
]);

it('lascia liberi i filtri e i campi della form', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(RiconciliazioniAttive::class)
        ->set('from', '2026-01-01')
        ->set('until', '2026-03-31')
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(AssistenteAi::class)
        ->set('draft', 'quanto ho speso a luglio?')
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(AssetScan::class)
        ->set('code', 'ASSET-1')
        ->assertOk();
});
