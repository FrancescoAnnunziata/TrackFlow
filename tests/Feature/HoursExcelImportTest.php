<?php

use App\Models\Client;
use App\Models\Hour;
use App\Models\User;
use App\Services\Import\HoursExcelImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sampleRows(): array
{
    return [
        ['A' => 'Data', 'B' => 'Cliente', 'C' => 'Ore', 'D' => 'Attività'],
        ['A' => '46174', 'B' => 'Fioravanti', 'C' => '10', 'D' => 'Trasferta'],
        ['A' => '46177', 'B' => 'Fioravanti', 'C' => '0.5', 'D' => 'Supporto da remoto'],
        ['A' => '46180', 'B' => 'Sconosciuto', 'C' => '2', 'D' => 'X'], // cliente non trovato
        ['A' => '', 'B' => '', 'C' => '', 'D' => ''],                    // riga vuota
    ];
}

$map = ['date' => 'Data', 'client' => 'Cliente', 'hours' => 'Ore', 'notes' => 'Attività'];

it('converts excel serial dates', function () {
    expect(app(HoursExcelImporter::class)->excelDate('46174')->format('Y-m-d'))->toBe('2026-06-01');
});

it('imports hours and matches clients flexibly', function () use ($map) {
    $user = User::factory()->create();
    $client = Client::create(['name' => 'FIORAVANTI S.R.L.']); // il file dice solo "Fioravanti"

    $res = app(HoursExcelImporter::class)->importRows(sampleRows(), $user->id, true, $map);

    expect($res['imported'])->toBe(2);
    expect($res['skipped'])->toBe(1);
    expect($res['unmatched'])->toBe(['Sconosciuto']);

    $hour = Hour::orderBy('date')->first();
    expect($hour->date->format('Y-m-d'))->toBe('2026-06-01');
    expect((float) $hour->hours)->toBe(10.0);
    expect($hour->notes)->toBe('Trasferta');
    expect($hour->billable)->toBeTrue();
    expect($hour->client->id)->toBe($client->id);
});

it('honours a forced client for every row', function () use ($map) {
    $user = User::factory()->create();
    Client::create(['name' => 'FIORAVANTI S.R.L.']);
    $forced = Client::create(['name' => 'Cliente Forzato']);

    $res = app(HoursExcelImporter::class)->importRows(sampleRows(), $user->id, false, $map, $forced->id);

    // Anche la riga "Sconosciuto" viene importata perché si forza il cliente.
    expect($res['imported'])->toBe(3);
    expect($res['unmatched'])->toBe([]);
    expect(Hour::first()->client->id)->toBe($forced->id);
    expect(Hour::first()->billable)->toBeFalse();
});

it('renders the hours list with the import action for admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/hours')
        ->assertOk()
        ->assertSee('Importa Excel');
});
