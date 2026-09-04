<?php

use App\Filament\Resources\Hours\Pages\ListHours;
use App\Models\Client;
use App\Models\Hour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exports hours to an excel file for admins', function () {
    $admin = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Calzedonia']);
    $hour = Hour::create([
        'user_id' => $admin->id,
        'date' => '2026-05-10',
        'hours' => 8,
        'billable' => true,
        'notes' => 'Trasferta sede',
    ]);
    $hour->update(['client_id' => $client->id]);

    $this->actingAs($admin);

    Livewire::test(ListHours::class)
        ->callAction('exportExcel')
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('ore-'.now()->format('Y-m-d').'.xlsx');
});

it('warns instead of downloading when there are no hours', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ListHours::class)
        ->callAction('exportExcel')
        ->assertNotified();
});

it('shows the export button to admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/hours')
        ->assertOk()
        ->assertSee('Esporta Excel');
});
