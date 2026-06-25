<?php

use App\Enums\DeviceStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use App\Enums\TicketStatus;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMaintenance;
use App\Models\DeviceSecurityCheck;
use App\Models\SecurityFinding;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function makeClient(string $prefix = 'TST', string $name = 'Cliente Test'): Client
{
    return Client::create(['name' => $name, 'asset_prefix' => $prefix]);
}

function clientUserFor(Client $client): User
{
    return User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
}

it('generates sequential asset code, qr token and barcode per client', function () {
    $client = makeClient('FED');

    $first = Device::create(['client_id' => $client->id, 'name' => 'Notebook 1']);
    $second = Device::create(['client_id' => $client->id, 'name' => 'Notebook 2']);

    expect($first->asset_code)->toBe('G8-FED-0001')
        ->and($second->asset_code)->toBe('G8-FED-0002')
        ->and($first->barcode)->toBe('G8-FED-0001')
        ->and($first->qr_token)->not->toBeNull()
        ->and($first->qr_token)->not->toBe($second->qr_token);
});

it('derives the asset prefix from the client name when none is set', function () {
    $client = Client::create(['name' => 'Acme Industries']);

    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);

    expect($device->asset_code)->toBe('G8-ACM-0001');
});

it('backfills client_id from the device on related records', function () {
    $this->actingAs(User::factory()->create());

    $client = makeClient();
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);

    $maintenance = DeviceMaintenance::create([
        'device_id' => $device->id,
        'maintenance_date' => now(),
        'type' => 'ordinary',
    ]);

    $check = DeviceSecurityCheck::create([
        'device_id' => $device->id,
        'checked_at' => now(),
        'risk_level' => SecurityRiskLevel::Low,
        'outcome' => SecurityOutcome::Compliant,
    ]);

    $finding = SecurityFinding::create([
        'device_id' => $device->id,
        'title' => 'Test',
        'severity' => FindingSeverity::Medium,
        'status' => FindingStatus::Open,
    ]);

    $ticket = SupportTicket::create([
        'device_id' => $device->id,
        'title' => 'Non si accende',
    ]);

    expect($maintenance->client_id)->toBe($client->id)
        ->and($check->client_id)->toBe($client->id)
        ->and($finding->client_id)->toBe($client->id)
        ->and($ticket->client_id)->toBe($client->id);
});

it('generates findings only for failed controls with mapped severity', function () {
    $this->actingAs(User::factory()->create());

    $client = makeClient();
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);

    $check = DeviceSecurityCheck::create([
        'device_id' => $device->id,
        'checked_at' => now(),
        'risk_level' => SecurityRiskLevel::High,
        'outcome' => SecurityOutcome::NonCompliant,
        'antivirus_active' => false,   // critical
        'firewall_active' => false,    // high
        'os_updated' => true,          // passed -> no finding
        'mfa_enabled' => null,         // not evaluated -> no finding
    ]);

    $check->generateFindingsForFailures();

    $findings = $check->findings()->get();

    expect($findings)->toHaveCount(2);

    $antivirus = $findings->firstWhere('title', 'Antivirus non attivo');
    expect($antivirus)->not->toBeNull()
        ->and($antivirus->severity)->toBe(FindingSeverity::Critical)
        ->and($antivirus->client_id)->toBe($client->id);

    $firewall = $findings->firstWhere('title', 'Firewall non attivo');
    expect($firewall->severity)->toBe(FindingSeverity::High);
});

it('stamps resolved_at and closed_at when the ticket status changes', function () {
    $this->actingAs(User::factory()->create());

    $client = makeClient();
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);

    $ticket = SupportTicket::create([
        'device_id' => $device->id,
        'title' => 'Test',
        'status' => TicketStatus::Open,
    ]);

    expect($ticket->resolved_at)->toBeNull();

    $ticket->update(['status' => TicketStatus::Resolved]);
    expect($ticket->fresh()->resolved_at)->not->toBeNull();

    $ticket->update(['status' => TicketStatus::Closed]);
    expect($ticket->fresh()->closed_at)->not->toBeNull();
});

it('assigns and returns a device updating status and assignment history', function () {
    $client = makeClient();
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);
    $employee = User::factory()->create();

    $device->assignTo($employee, 'Consegna iniziale');

    expect($device->status)->toBe(DeviceStatus::Assigned)
        ->and($device->assigned_user_id)->toBe($employee->id)
        ->and($device->assignments()->whereNull('returned_at')->count())->toBe(1);

    $device->returnFromUser('Rientro');

    expect($device->status)->toBe(DeviceStatus::InStock)
        ->and($device->assigned_user_id)->toBeNull()
        ->and($device->assignments()->whereNull('returned_at')->count())->toBe(0)
        ->and($device->assignments()->count())->toBe(1);
});

it('redirects authorized users from the qr lookup to the device page', function () {
    $client = makeClient();
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('assets.lookup', $device->qr_token))
        ->assertRedirect(DeviceResource::getUrl('view', ['record' => $device]));
});

it('shows a minimal public page on qr lookup for guests', function () {
    $client = makeClient('FED');
    $device = Device::create(['client_id' => $client->id, 'name' => 'PC']);

    $this->get(route('assets.lookup', $device->qr_token))
        ->assertOk()
        ->assertSee($device->asset_code)
        ->assertSee($client->name);
});

it('returns 404 on qr lookup for an unknown token', function () {
    $this->get(route('assets.lookup', 'non-existent-token'))
        ->assertNotFound();
});

it('forbids a client from printing the label of another client device', function () {
    $clientA = makeClient('AAA', 'Cliente A');
    $clientB = makeClient('BBB', 'Cliente B');
    $deviceA = Device::create(['client_id' => $clientA->id, 'name' => 'PC A']);
    $deviceB = Device::create(['client_id' => $clientB->id, 'name' => 'PC B']);

    $clientUser = clientUserFor($clientB);

    $this->actingAs($clientUser)
        ->get(route('assets.label', $deviceB))
        ->assertOk();

    $this->actingAs($clientUser)
        ->get(route('assets.label', $deviceA))
        ->assertForbidden();
});

it('scopes the devices table so a client only sees its own devices', function () {
    $clientA = makeClient('AAA', 'Cliente A');
    $clientB = makeClient('BBB', 'Cliente B');
    $deviceA = Device::create(['client_id' => $clientA->id, 'name' => 'PC A']);
    $deviceB = Device::create(['client_id' => $clientB->id, 'name' => 'PC B']);

    $clientUser = clientUserFor($clientA);

    Livewire::actingAs($clientUser)
        ->test(ListDevices::class)
        ->assertCanSeeTableRecords([$deviceA])
        ->assertCanNotSeeTableRecords([$deviceB]);
});
