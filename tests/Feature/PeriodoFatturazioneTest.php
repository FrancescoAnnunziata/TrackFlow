<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Client;
use App\Models\Expense;
use App\Models\User;
use App\Support\PeriodoFatturazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

/** Un cliente come Alsea: a ore, trimestrale anticipato, con conguaglio. */
function clienteAnticipato(): Client
{
    return Client::create([
        'name' => 'Anticipato SpA',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_timing' => Client::TIMING_ADVANCE,
        'billing_period_months' => 3,
        'reconcile_previous_period' => true,
        'default_hourly_rate' => 50,
        'minimum_hours_per_month' => 20,
        'vat_rate' => 22,
    ]);
}

it('sul posticipato le spese seguono il periodo fatturato', function () {
    $client = Client::create([
        'name' => 'Posticipato SRL',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_timing' => Client::TIMING_ARREARS,
        'billing_period_months' => 1,
        'default_hourly_rate' => 50,
    ]);

    $periodo = PeriodoFatturazione::per($client, Carbon::parse('2026-08-01'));

    expect($periodo->da->toDateString())->toBe('2026-08-01')
        ->and($periodo->a->toDateString())->toBe('2026-08-31')
        ->and($periodo->conguaglioDa)->toBeNull()
        ->and($periodo->speseDa->toDateString())->toBe('2026-08-01')
        ->and($periodo->speseSfasate())->toBeFalse();
});

it('sull\'anticipato con conguaglio le spese arrivano dal trimestre precedente', function () {
    $periodo = PeriodoFatturazione::per(clienteAnticipato(), Carbon::parse('2026-09-01'));

    expect($periodo->da->toDateString())->toBe('2026-09-01')
        ->and($periodo->a->toDateString())->toBe('2026-11-30')
        ->and($periodo->conguaglioDa->toDateString())->toBe('2026-06-01')
        ->and($periodo->conguaglioA->toDateString())->toBe('2026-08-31')
        ->and($periodo->speseDa->toDateString())->toBe('2026-06-01')
        ->and($periodo->speseA->toDateString())->toBe('2026-08-31')
        ->and($periodo->speseSfasate())->toBeTrue();
});

it('forfait e a giornata non conguagliano nemmeno se anticipati', function () {
    foreach ([Client::MODEL_FORFAIT, Client::MODEL_DAILY] as $modello) {
        $client = Client::create([
            'name' => 'Anticipato '.$modello,
            'invoicing_provider' => Client::PROVIDER_FIC,
            'billing_model' => $modello,
            'billing_timing' => Client::TIMING_ADVANCE,
            'billing_period_months' => 3,
            'reconcile_previous_period' => true,
            'forfait_amount' => 1000,
            'daily_rate' => 290,
        ]);

        expect(PeriodoFatturazione::conguaglia($client))->toBeFalse();
        expect(PeriodoFatturazione::per($client, Carbon::parse('2026-09-01'))->speseSfasate())->toBeFalse();
    }
});

it('il riepilogo nel modale dice da che periodo arrivano le spese', function () {
    $client = clienteAnticipato();

    // Spese di agosto: appartengono al trimestre che si conguaglia generando
    // il trimestre settembre-novembre, non a quello giugno-agosto.
    $chi = auth()->id();
    Expense::create(['user_id' => $chi, 'client_id' => $client->id, 'date' => '2026-08-06', 'amount' => 26, 'description' => 'Taxi']);
    Expense::create(['user_id' => $chi, 'client_id' => $client->id, 'date' => '2026-08-28', 'amount' => 99, 'description' => 'Hotel']);

    $riepilogo = fn (string $inizio): string => (string) Livewire::test(ListInvoices::class)
        ->instance()
        ->riepilogo($client->id, $inizio);

    expect($riepilogo('2026-09-01'))
        ->toContain('2 spese')
        ->toContain('€ 125,00')
        ->toContain('06/2026 – 08/2026')
        ->toContain('anticipato');

    expect($riepilogo('2026-08-01'))
        ->toContain('nessuna spesa da riaddebitare in 05/2026 – 07/2026');
});

it('il riepilogo distingue il canale e l\'IVA del cliente', function () {
    $fiscozen = Client::create([
        'name' => 'Forfettario SRL',
        'invoicing_provider' => Client::PROVIDER_FISCOZEN,
        'billing_model' => Client::MODEL_DAILY,
        'billing_timing' => Client::TIMING_ARREARS,
        'billing_period_months' => 1,
        'daily_rate' => 290,
        'vat_rate' => 0,
    ]);

    $riepilogo = Livewire::test(ListInvoices::class)
        ->instance()
        ->riepilogo($fiscozen->id, '2026-08-01');

    expect((string) $riepilogo)
        ->toContain('Fiscozen')
        ->toContain('ricreare a mano')
        ->toContain('regime forfettario')
        ->toContain('a giornata € 290,00/gg')
        ->toContain('mensile');
});
