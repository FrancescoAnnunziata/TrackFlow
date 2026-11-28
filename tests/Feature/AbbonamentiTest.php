<?php

use App\Assistant\Tools\ProposeAbbonamentoTool;
use App\Assistant\Tools\ReadAbbonamentiTool;
use App\Filament\Pages\AssistenteAi;
use App\Filament\Resources\Abbonamenti\Pages\ListAbbonamenti;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\PassiveInvoices\Pages\ListPassiveInvoices;
use App\Models\Abbonamento;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\AbbonamentiInRinnovoNotification;
use App\Services\Abbonamenti\SegnaAbbonamento;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:00'));
});

function uscita(string $date, float $amount, string $description = 'PAGAMENTO TRAMITE POS Pagamento e-commerce C/O NY VMO*VIMEO.COM Carta 1234'): BankTransaction
{
    $account = BankAccount::firstOrCreate(['bank_key' => 'inbank'], ['name' => 'InBank']);

    return BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => $date, 'amount' => -$amount,
        'direction' => 'out', 'description' => $description, 'dedup_hash' => uniqid('a', true),
    ]);
}

it('computes the next renewal and rolls an old charge forward past today', function () {
    expect(Abbonamento::nextRenewalAfter(CarbonImmutable::parse('2026-10-03'), 'annual')->format('Y-m-d'))->toBe('2027-10-03');
    expect(Abbonamento::nextRenewalAfter(CarbonImmutable::parse('2026-10-03'), 'quarterly')->format('Y-m-d'))->toBe('2027-01-03');
    // Un addebito di due anni fa non deve lasciare una scadenza nel passato.
    expect(Abbonamento::nextRenewalAfter(CarbonImmutable::parse('2024-11-15'), 'annual')->format('Y-m-d'))->toBe('2027-11-15');
});

it('creates a subscription, then treats a later charge with the same name as its renewal', function () {
    $service = app(SegnaAbbonamento::class);

    $first = $service->segna('Vimeo', 'annual', CarbonImmutable::parse('2025-12-24'), 117.12, null);
    expect($first->next_renewal_on->format('Y-m-d'))->toBe('2026-12-24');

    $renewal = $service->segna('vimeo', 'annual', CarbonImmutable::parse('2026-12-24'), 129.00, null);
    expect($renewal->id)->toBe($first->id);
    expect($renewal->next_renewal_on->format('Y-m-d'))->toBe('2027-12-24');
    expect((float) $renewal->amount)->toBe(129.0);

    // Un addebito arretrato non fa tornare indietro la scadenza.
    $old = $service->segna('Vimeo', 'annual', CarbonImmutable::parse('2024-12-24'), 99.00, null);
    expect($old->next_renewal_on->format('Y-m-d'))->toBe('2027-12-24');
    expect((float) $old->amount)->toBe(129.0);
    expect(Abbonamento::count())->toBe(1);
});

it('marks a bank movement as a subscription from the movements list', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tx = uscita('2025-12-29', 117.12);

    Livewire::test(ListBankTransactions::class)
        ->callAction(TestAction::make('segnaAbbonamento')->table($tx), [
            'existing_id' => null, 'name' => 'Vimeo', 'cadence' => 'annual', 'amount' => 117.12,
        ])
        ->assertHasNoActionErrors();

    $a = Abbonamento::sole();
    expect($a->name)->toBe('Vimeo');
    expect($a->next_renewal_on->format('Y-m-d'))->toBe('2026-12-29');
    expect($a->last_charged_on->format('Y-m-d'))->toBe('2025-12-29');
});

it('marks a passive invoice as the renewal of an existing subscription', function () {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::create(['name' => 'SiteGround Spain S.L.']);
    $existing = Abbonamento::create([
        'name' => 'SiteGround — dominio esempio.it', 'supplier_id' => $supplier->id, 'cadence' => 'annual',
        'amount' => 12.99, 'last_charged_on' => '2025-11-20', 'next_renewal_on' => '2026-11-20',
    ]);
    $invoice = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => '4999999', 'type' => 'expense',
        'document_date' => '2026-11-21', 'amount_net' => 14.99, 'amount_vat' => 0, 'amount_gross' => 14.99,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);

    Livewire::test(ListPassiveInvoices::class)
        ->callAction(TestAction::make('segnaAbbonamento')->table($invoice), [
            'existing_id' => $existing->id, 'cadence' => 'annual', 'amount' => 14.99,
        ])
        ->assertHasNoActionErrors();

    expect(Abbonamento::count())->toBe(1);
    $existing->refresh();
    expect($existing->next_renewal_on->format('Y-m-d'))->toBe('2027-11-21');
    expect((float) $existing->amount)->toBe(14.99);
});

it('sends one reminder a week before the renewal, and only once', function () {
    Notification::fake();
    config(['services.abbonamenti.promemoria_a' => 'giorgio@example.test']);

    $due = Abbonamento::create(['name' => 'Vimeo', 'cadence' => 'annual', 'amount' => 117.12, 'next_renewal_on' => '2026-12-05']);
    Abbonamento::create(['name' => 'Lontano', 'cadence' => 'annual', 'amount' => 10, 'next_renewal_on' => '2027-03-01']);

    $this->artisan('abbonamenti:promemoria')->assertSuccessful();

    Notification::assertSentTo(
        new AnonymousNotifiable,
        AbbonamentiInRinnovoNotification::class,
        fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'giorgio@example.test'
            && $n->abbonamenti->pluck('id')->all() === [$due->id],
    );
    expect($due->fresh()->reminded_for->format('Y-m-d'))->toBe('2026-12-05');

    // Il giorno dopo la stessa scadenza non riparte.
    $this->travelTo(CarbonImmutable::parse('2026-11-29 09:00'));
    $this->artisan('abbonamenti:promemoria')->assertSuccessful();
    Notification::assertSentTimes(AbbonamentiInRinnovoNotification::class, 1);
});

it('moves a renewal that passed without a new charge to the next cycle', function () {
    Notification::fake();
    $a = Abbonamento::create(['name' => 'Webflow', 'cadence' => 'annual', 'next_renewal_on' => '2026-11-15']);
    $q = Abbonamento::create(['name' => 'Trimestrale', 'cadence' => 'quarterly', 'next_renewal_on' => '2026-08-01']);

    $this->artisan('abbonamenti:promemoria')->assertSuccessful();

    expect($a->fresh()->next_renewal_on->format('Y-m-d'))->toBe('2027-11-15');
    expect($q->fresh()->next_renewal_on->format('Y-m-d'))->toBe('2027-02-01');
    Notification::assertNothingSent();
});

it('serves the renewals calendar only through the signed link', function () {
    Abbonamento::create(['name' => 'Vimeo', 'cadence' => 'annual', 'amount' => 117.12, 'next_renewal_on' => '2026-12-24']);

    $this->get('/abbonamenti/calendario.ics')->assertForbidden();

    $response = $this->get(URL::signedRoute('abbonamenti.calendario'))->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/calendar');
    expect($response->getContent())
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('DTSTART;VALUE=DATE:20261224')
        ->toContain('SUMMARY:Rinnovo Vimeo — € 117\,12');
});

it('shows the subscriptions page with the calendar link to finance users only', function () {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ListAbbonamenti::class)
        ->assertOk()
        ->mountAction('calendario')
        ->assertMountedActionModalSeeHtml('abbonamenti/calendario.ics?signature=');

    $this->actingAs(User::factory()->create(['role' => 'accountant']));
    $this->get('/abbonamenti')->assertForbidden();
});

it('lets the assistant read subscriptions and propose one, applied on confirmation', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tx = uscita('2026-10-03', 346.80, 'PAGAMENTO TRAMITE POS Pagamento e-commerce C/O Stuttgart Anydesk Carta 1234');

    $res = app(ProposeAbbonamentoTool::class)->run(['movement_id' => $tx->id, 'cadence' => 'annual', 'name' => 'AnyDesk']);
    expect($res->isError)->toBeFalse();
    expect($res->action['type'])->toBe('subscription');
    expect($res->action['next_renewal_on'])->toBe('2027-10-03');
    expect(Abbonamento::count())->toBe(0); // la proposta non scrive

    $thread = AssistantThread::create(['user_id' => auth()->id()]);
    $msg = AssistantMessage::create([
        'assistant_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'proposta', 'status' => 'done',
        'actions' => [['id' => 's1', 'status' => 'pending'] + $res->action],
    ]);
    Livewire::test(AssistenteAi::class)->call('openThread', $thread->id)->call('confirmProposal', $msg->id, 's1');

    $a = Abbonamento::sole();
    expect($a->name)->toBe('AnyDesk');
    expect($a->next_renewal_on->format('Y-m-d'))->toBe('2027-10-03');
    expect(AssistantMessage::find($msg->id)->actions[0]['status'])->toBe('applied');

    $read = app(ReadAbbonamentiTool::class)->run(['search' => 'any']);
    expect($read->content)->toContain('AnyDesk')->toContain('03/10/2027');
});

it('refuses monthly cadences and incoming movements', function () {
    $in = BankTransaction::create([
        'bank_account_id' => BankAccount::create(['name' => 'Vivid', 'bank_key' => 'vivid'])->id,
        'booked_at' => '2026-10-01', 'amount' => 100, 'direction' => 'in', 'description' => 'Incasso', 'dedup_hash' => 'in1',
    ]);
    $out = uscita('2026-10-01', 26.00, 'MEILI');

    expect(app(ProposeAbbonamentoTool::class)->run(['movement_id' => $out->id, 'cadence' => 'monthly'])->isError)->toBeTrue();
    expect(app(ProposeAbbonamentoTool::class)->run(['movement_id' => $in->id, 'cadence' => 'annual'])->isError)->toBeTrue();
});
