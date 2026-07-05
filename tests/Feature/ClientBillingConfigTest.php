<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\ClientUserRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function billableClient(): Client
{
    return Client::create([
        'name' => 'Fioravanti',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 1,
        'billing_timing' => Client::TIMING_ARREARS,
        'entity_type' => 'company',
        'vat_rate' => 22,
    ]);
}

it('saves a client with several comma-separated emails', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = billableClient();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['email' => 'simona@fioravanti.com,roberta@fioravanti.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->fresh()->email)->toBe('simona@fioravanti.com,roberta@fioravanti.com');
});

it('rejects a malformed email in the list', function () {
    $this->actingAs(User::factory()->admin()->create());
    $client = billableClient();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['email' => 'ok@x.com, non-una-email'])
        ->call('save')
        ->assertHasFormErrors(['email']);
});

it('renders the client create form with the billing section', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/clients/create')
        ->assertOk()
        ->assertSee('Fatturazione');
});

it('renders the client view page with fiscal and billing sections', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::create([
        'name' => 'Acme',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'default_hourly_rate' => 50,
        'vat_number' => '01234567890',
        'address_city' => 'Milano',
    ]);

    $this->actingAs($admin)
        ->get('/clients/'.$client->id)
        ->assertOk()
        ->assertSee('Dati fiscali')
        ->assertSee('Fatturazione')
        ->assertSee('01234567890')
        ->assertSee('Milano');
});

it('persists the billing configuration', function () {
    $client = Client::create([
        'name' => 'Alsea',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'billing_period_months' => 3,
        'billing_timing' => Client::TIMING_ADVANCE,
        'reconcile_previous_period' => true,
        'default_hourly_rate' => 50,
        'minimum_hours_per_month' => 20,
        'monthly_extra_amount' => 50,
        'vat_rate' => 22,
    ]);

    $fresh = $client->fresh();

    expect($fresh->billing_period_months)->toBe(3);
    expect($fresh->reconcile_previous_period)->toBeTrue();
    expect((float) $fresh->default_hourly_rate)->toBe(50.0);
    expect($fresh->isBillableHere())->toBeTrue();
});

it('resolves per-user rates with fallback to the default', function () {
    $giorgio = User::factory()->create();
    $annunziata = User::factory()->create();
    $other = User::factory()->create();

    $client = Client::create([
        'name' => 'Acme',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'billing_model' => Client::MODEL_HOURLY,
        'default_hourly_rate' => 90,
    ]);

    ClientUserRate::create(['client_id' => $client->id, 'user_id' => $annunziata->id, 'hourly_rate' => 40]);

    expect($client->rateForUser($annunziata->id))->toBe(40.0);   // override
    expect($client->rateForUser($giorgio->id))->toBe(90.0);      // default
    expect($client->rateForUser($other->id))->toBe(90.0);        // default
});

it('marks non-fatture-in-cloud clients as not billable here', function () {
    $client = Client::create([
        'name' => 'Cliente Fiscozen',
        'invoicing_provider' => 'fiscozen',
    ]);

    expect($client->isBillableHere())->toBeFalse();
});
