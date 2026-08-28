<?php

use App\Models\User;
use App\Services\Security\EndpointScriptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('bakes the current windows_eol config into the downloaded script', function () {
    config()->set('inventario_endpoint.windows_eol.dates', ['24H2' => '2026-10-13']);
    config()->set('inventario_endpoint.windows_eol.windows10_eol', '2025-10-14');

    $script = app(EndpointScriptBuilder::class)->build();

    expect($script)->toContain("'24H2' = '2026-10-13'")
        ->and($script)->toContain('Win10 EOL 2025-10-14')
        // I placeholder non devono mai restare nel file scaricato.
        ->and($script)->not->toContain('{{TRACKFLOW:')
        // Deve restare uno script PowerShell valido, non solo un frammento.
        ->and($script)->toContain('[CmdletBinding()]')
        ->and($script)->toContain('Export-Csv -Path $csvFile');
});

it('reflects a config change on the next download without touching any file', function () {
    config()->set('inventario_endpoint.windows_eol.dates', ['24H2' => '2026-10-13']);
    $before = app(EndpointScriptBuilder::class)->build();

    config()->set('inventario_endpoint.windows_eol.dates', ['24H2' => '2026-10-13', '25H2' => '2027-04-01']);
    $after = app(EndpointScriptBuilder::class)->build();

    expect($before)->not->toContain('25H2')
        ->and($after)->toContain("'25H2' = '2027-04-01'");
});

it('lets staff download the script but blocks client accounts', function () {
    $staff = User::factory()->create(['role' => 'admin']);
    $client = User::factory()->create(['role' => 'client']);

    $this->actingAs($staff)
        ->get(route('inventario.script'))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Inventario-Sicurezza.ps1"');

    $this->actingAs($client)
        ->get(route('inventario.script'))
        ->assertForbidden();
});
