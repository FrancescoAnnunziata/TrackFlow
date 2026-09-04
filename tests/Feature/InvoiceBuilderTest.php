<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Client;
use App\Models\ClientUserRate;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function loggedHour(Client $client, User $user, string $date, float $hours, bool $billable = true): Hour
{
    $h = Hour::create(['user_id' => $user->id, 'date' => $date, 'hours' => $hours, 'billable' => $billable]);
    $h->update(['client_id' => $client->id]);

    return $h;
}

it('renders the invoices list and create pages', function () {
    $this->get('/invoices')->assertOk();
    $this->get('/invoices/create')->assertOk();
});

it('generates an invoice from the list "Genera fattura" action', function () {
    $user = User::factory()->create(['name' => 'Giorgio']);
    $client = Client::create([
        'name' => 'Acme',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'default_hourly_rate' => 50,
        'vat_rate' => 22,
    ]);
    loggedHour($client, $user, '2026-06-05', 4);

    Livewire::test(ListInvoices::class)
        ->callAction('generate', ['client_id' => $client->id, 'period_start' => '2026-06-01']);

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->items)->not->toBeEmpty();
});

it('builds a forfait invoice with a single consulting line', function () {
    $client = Client::create([
        'name' => 'Forfait SpA',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_FORFAIT,
        'billing_period_months' => 1,
        'forfait_amount' => 1000,
        'vat_rate' => 22,
    ]);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    expect($invoice->items)->toHaveCount(1);
    expect($invoice->items->first()->net_price + 0)->toBe(1000.0);
    expect($invoice->total())->toBe(1220.0);
});

it('splits a forfait into configured detail lines', function () {
    $client = Client::create([
        'name' => 'Fedespedi',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_FORFAIT,
        'billing_period_months' => 1,
        'forfait_amount' => 3600,
        'forfait_lines' => [
            ['name' => 'Consulenza Progetti digitali', 'amount' => 1800],
            ['name' => 'Consulenza supporto Federazione', 'amount' => 1800],
        ],
        'vat_rate' => 22,
    ]);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $consulting = $invoice->items->where('line_kind', 'consulting');
    expect($consulting)->toHaveCount(2);
    expect($consulting->firstWhere('name', 'Consulenza Progetti digitali')->net_price + 0)->toBe(1800.0);
    expect($invoice->taxableAmount())->toBe(3600.0);
    expect($invoice->total())->toBe(4392.0); // 3600 + 22%
});

it('multiplies forfait detail lines by the number of months', function () {
    $client = Client::create([
        'name' => 'ForfaitTrim',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_FORFAIT,
        'billing_period_months' => 3,
        'forfait_lines' => [['name' => 'Canone', 'amount' => 1000]],
        'vat_rate' => 22,
    ]);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-04-01'));

    expect($invoice->items->firstWhere('line_kind', 'consulting')->net_price + 0)->toBe(3000.0); // 1000 × 3 mesi
});

it('bills a daily-rate client in rounded-up days', function () {
    $user = User::factory()->create();
    $client = Client::create([
        'name' => 'Calzedonia',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_DAILY,
        'billing_period_months' => 1,
        'hours_per_day' => 8,
        'daily_rate' => 400,
        'vat_rate' => 22,
    ]);
    loggedHour($client, $user, '2026-06-05', 40);
    loggedHour($client, $user, '2026-06-10', 34); // totale 74h

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $line = $invoice->items->firstWhere('line_kind', 'consulting');
    expect((float) $line->qty)->toBe(10.0);        // ceil(74 / 8)
    expect($line->measure)->toBe('gg');
    expect((float) $line->net_price)->toBe(400.0);
    expect($invoice->taxableAmount())->toBe(4000.0); // 10 × 400
});

it('bills expenses for forfait clients too', function () {
    $user = User::factory()->create();
    $client = Client::create([
        'name' => 'ForfaitConSpese',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_FORFAIT,
        'billing_period_months' => 1,
        'forfait_amount' => 1000,
        'vat_rate' => 22,
    ]);
    Expense::create(['user_id' => $user->id, 'client_id' => $client->id, 'date' => '2026-06-10', 'amount' => 50, 'notes' => 'pedaggio']);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $expenses = $invoice->items->firstWhere('line_kind', 'expenses');
    expect($expenses)->not->toBeNull();
    expect($expenses->vat_kind)->toBe('art15');
    expect((float) $expenses->net_price)->toBe(50.0);
});

it('builds per-user hourly lines and ignores non-billable hours', function () {
    $giorgio = User::factory()->create(['name' => 'Giorgio']);
    $annunziata = User::factory()->create(['name' => 'Annunziata']);

    $client = Client::create([
        'name' => 'Acme',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'default_hourly_rate' => 90,
        'vat_rate' => 22,
    ]);
    ClientUserRate::create(['client_id' => $client->id, 'user_id' => $annunziata->id, 'hourly_rate' => 40]);

    loggedHour($client, $giorgio, '2026-06-05', 10);
    loggedHour($client, $annunziata, '2026-06-06', 5);
    loggedHour($client, $annunziata, '2026-06-07', 3, billable: false); // esclusa

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $byName = $invoice->items->keyBy('name');
    // Giorgio: 10h × 90; Annunziata: 5h × 40 (le 3h non billable escluse).
    $giorgioLine = $invoice->items->firstWhere('name', 'like', '%Giorgio%') ?? $invoice->items->first(fn ($i) => str_contains($i->name, 'Giorgio'));
    expect($invoice->items)->toHaveCount(2);
    $g = $invoice->items->first(fn ($i) => str_contains($i->name, 'Giorgio'));
    $a = $invoice->items->first(fn ($i) => str_contains($i->name, 'Annunziata'));
    expect((float) $g->qty)->toBe(10.0);
    expect((float) $g->net_price)->toBe(90.0);
    expect((float) $a->qty)->toBe(5.0);
    expect((float) $a->net_price)->toBe(40.0);
    // Ore agganciate solo quelle billable.
    expect($invoice->hours)->toHaveCount(2);
});

it('tops up to the guaranteed minimum when hours worked are below it', function () {
    $user = User::factory()->create(['name' => 'Giorgio']);
    $client = Client::create([
        'name' => 'MinCliente',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'default_hourly_rate' => 50,
        'minimum_hours_per_month' => 20,
        'vat_rate' => 22,
    ]);

    loggedHour($client, $user, '2026-06-10', 15); // sotto il minimo di 20

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $topUp = $invoice->items->first(fn ($i) => str_contains($i->name, 'minimo garantito'));
    expect($topUp)->not->toBeNull();
    expect((float) $topUp->qty)->toBe(5.0); // 20 - 15
    // 15h×50 + 5h×50 = 1000 imponibile
    expect($invoice->taxableAmount())->toBe(1000.0);
});

it('builds an Alsea-style advance + reconciliation invoice', function () {
    $user = User::factory()->create(['name' => 'Giorgio']);
    $client = Client::create([
        'name' => 'Alsea',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 3,
        'billing_timing' => Client::TIMING_ADVANCE,
        'reconcile_previous_period' => true,
        'default_hourly_rate' => 50,
        'minimum_hours_per_month' => 20,
        'vat_rate' => 22,
    ]);

    // Trimestre precedente (gen-mar): lavorate 70h (garantite 60 → 10 extra) + spese.
    loggedHour($client, $user, '2026-01-15', 30);
    loggedHour($client, $user, '2026-02-15', 25);
    loggedHour($client, $user, '2026-03-15', 15);
    Expense::create(['user_id' => $user->id, 'client_id' => $client->id, 'date' => '2026-02-10', 'amount' => 143.5, 'notes' => 'treno']);

    // Fattura a inizio Q2 (apr-giu): anticipo nuovo trimestre + conguaglio Q1.
    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-04-01'));

    $advance = $invoice->items->first(fn ($i) => $i->line_kind === 'advance');
    $recon = $invoice->items->first(fn ($i) => $i->line_kind === 'reconciliation');
    $expenses = $invoice->items->first(fn ($i) => $i->line_kind === 'expenses');

    expect((float) $advance->qty)->toBe(60.0);      // 20 × 3 mesi anticipati
    expect((float) $advance->net_price)->toBe(50.0);
    expect((float) $recon->qty)->toBe(10.0);        // 70 lavorate - 60 garantite
    expect($expenses->vat_kind)->toBe('art15');
    expect((float) $expenses->net_price)->toBe(143.5);
    // Totale: (60+10)×50 = 3500 imponibile, +22% = 4270, +143.5 art15 = 4413.5
    expect($invoice->total())->toBe(4413.5);
});

it('uses the configured label for the extra recurring line', function () {
    $user = User::factory()->create();
    $client = Client::create([
        'name' => 'ExtraCliente',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'default_hourly_rate' => 50,
        'monthly_extra_amount' => 100,
        'monthly_extra_label' => 'Canone gestione',
        'vat_rate' => 22,
    ]);
    loggedHour($client, $user, '2026-06-05', 4);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $extra = $invoice->items->firstWhere('line_kind', 'extra');
    expect($extra)->not->toBeNull();
    expect($extra->name)->toBe('Canone gestione');
    expect((float) $extra->net_price)->toBe(100.0);
});

it('always aggregates expenses into a single art.15 line', function () {
    $user = User::factory()->create();
    $client = Client::create([
        'name' => 'SpeseCliente',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'default_hourly_rate' => 50,
        'vat_rate' => 22,
    ]);

    loggedHour($client, $user, '2026-06-05', 4);
    Expense::create(['user_id' => $user->id, 'client_id' => $client->id, 'date' => '2026-06-04', 'amount' => 23.5, 'notes' => 'pranzo']);
    Expense::create(['user_id' => $user->id, 'client_id' => $client->id, 'date' => '2026-06-06', 'amount' => 90, 'notes' => 'treno']);

    $invoice = app(InvoiceBuilder::class)->build($client, CarbonImmutable::parse('2026-06-01'));

    $expenseLines = $invoice->items->where('vat_kind', 'art15');
    expect($expenseLines)->toHaveCount(1);
    expect((float) $expenseLines->first()->net_price)->toBe(113.5); // 23.5 + 90
    expect($invoice->expenses)->toHaveCount(2);
});
