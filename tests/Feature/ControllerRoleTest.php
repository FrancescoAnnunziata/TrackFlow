<?php

use App\Filament\Pages\AssistenteAi;
use App\Filament\Pages\ControlloFinanziario;
use App\Filament\Pages\ElencoFattureEstere;
use App\Filament\Pages\FattureEstere;
use App\Filament\Pages\Guida;
use App\Filament\Pages\PrimaNota;
use App\Filament\Pages\RegistroAcquisti;
use App\Filament\Pages\RiconciliazioniAttive;
use App\Filament\Pages\RiconciliazioniPassive;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Corrispettivi\CorrispettivoResource;
use App\Filament\Resources\Costi\CostoResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$areaFinanziaria = fn (): array => [
    ControlloFinanziario::canAccess(),
    ElencoFattureEstere::canAccess(),
    FattureEstere::canAccess(),
    Guida::canAccess(),
    PrimaNota::canAccess(),
    RegistroAcquisti::canAccess(),
    RiconciliazioniAttive::canAccess(),
    RiconciliazioniPassive::canAccess(),
    AssistenteAi::canAccess(),
    BankTransactionResource::canViewAny(),
    ClientResource::canViewAny(),
    CorrispettivoResource::canViewAny(),
    CostoResource::canViewAny(),
    InvoiceResource::canViewAny(),
    PassiveInvoiceResource::canViewAny(),
    SupplierResource::canViewAny(),
];

it('opens the whole financial area to the controller', function () use ($areaFinanziaria) {
    $this->actingAs(User::factory()->create(['role' => 'controller']));

    expect($areaFinanziaria())->each->toBeTrue();
});

it('keeps the financial area closed to members and clients', function (string $role) use ($areaFinanziaria) {
    $this->actingAs(User::factory()->create(['role' => $role]));

    expect(collect($areaFinanziaria())->except(8)->all())->each->toBeFalse();
})->with(['member', 'client']);

it('does not make the controller an admin outside finance', function () {
    $controller = User::factory()->create(['role' => 'controller']);
    $this->actingAs($controller);

    expect($controller->isAdmin())->toBeFalse();
    // Gli utenti li vede anche un membro; crearli e cambiarne il ruolo no.
    expect(UserResource::canCreate())->toBeFalse();
    expect($controller->can('update', $controller))->toBeFalse();
});

it('lets the controller reach the Fatture in Cloud connection', function () {
    $this->actingAs(User::factory()->create(['role' => 'controller']))
        ->get('/fic/connect')
        ->assertRedirect();

    $this->actingAs(User::factory()->create(['role' => 'member']))
        ->get('/fic/connect')
        ->assertForbidden();
});
