<?php

use App\Enums\FindingStatus;
use App\Enums\SecurityOutcome;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\DeviceSecurityChecks\Pages\ListDeviceSecurityChecks;
use App\Filament\Resources\DeviceSecurityChecks\Pages\ViewDeviceSecurityCheck;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceSecurityCheck;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Services\Import\EndpointInventoryCsvImporter;
use App\Services\Security\EndpointHistory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Intestazione del CSV prodotto dallo script di censimento (60 colonne). */
function inventoryHeader(): array
{
    return [
        'DataRilevazione', 'RilevatoDa', 'EseguitoComeAdmin',
        'Assegnatario', 'Reparto', 'Ubicazione', 'StatoCicloVita', 'Note',
        'Hostname', 'Produttore', 'Modello', 'Seriale', 'RAM_GB', 'Dischi', 'UtenteLoggato', 'UltimoUtilizzoProfilo',
        'OS', 'OS_Edizione', 'OS_Versione', 'OS_Build', 'OS_Architettura', 'OS_Supporto', 'OS_DataInstallazione',
        'UltimoRiavvio', 'GiorniDaRiavvio',
        'UltimaPatch_Data', 'UltimaPatch_KB', 'GiorniDaUltimaPatch', 'RiavvioPendente',
        'AV_Prodotto', 'AV_TerzeParti', 'AV_RealTime', 'AV_ServizioAttivo', 'AV_FirmeAggiornateAl',
        'AV_FirmeGiorniEta', 'AV_TamperProtection', 'AV_UltimaScansione',
        'BitLocker_Stato', 'BitLocker_Protezione', 'BitLocker_Metodo', 'BitLocker_Protettori',
        'BitLocker_RecoveryKeyPresente', 'BitLocker_DoveECustoditaLaChiave', 'TPM_Presente', 'TPM_Pronto', 'SecureBoot',
        'MembriGruppoAdmin', 'AdminBuiltin_Nome', 'AdminBuiltin_Stato', 'AdminBuiltin_Rinominato', 'LAPS',
        'AccountLocaliAttivi',
        'Firewall', 'RDP', 'BloccoSchermo', 'TimeoutVideoAC_sec',
        'AzureAD_Joined', 'Dominio_Joined', 'InDominio', 'MDM_Enrolled', 'MDM_Url',
        'NumeroSoftwareInstallati', 'AppCritiche', 'StrumentiControlloRemoto', 'OneDrive',
        'Backup_Tipo', 'Backup_UltimoOK', 'Backup_UltimoRestoreTestato',
    ];
}

/** Riga di censimento reale (PC senza BitLocker, senza LAPS, admin locali extra). */
function inventoryRow(array $overrides = []): array
{
    $values = [
        'DataRilevazione' => '2026-08-28 06:47',
        'RilevatoDa' => 'franc',
        'EseguitoComeAdmin' => 'SI',
        'Assegnatario' => 'Mario Rossi',
        'Reparto' => '',
        'Ubicazione' => '',
        'StatoCicloVita' => '',
        'Note' => '',
        'Hostname' => 'DESKTOP-L1M3R8T',
        'Produttore' => 'Gigabyte Technology Co., Ltd.',
        'Modello' => 'B450 AORUS M',
        'Seriale' => 'Default string',
        'RAM_GB' => '15,9',
        'Dischi' => 'C: 931GB (liberi 117GB)',
        'UtenteLoggato' => 'DESKTOP-L1M3R8T\\franc',
        'UltimoUtilizzoProfilo' => '2026-08-28',
        'OS' => 'Microsoft Windows 11 Pro',
        'OS_Edizione' => 'Professional',
        'OS_Versione' => '25H2',
        'OS_Build' => '26200.9168',
        'OS_Architettura' => '64 bit',
        'OS_Supporto' => 'DA VERIFICARE',
        'OS_DataInstallazione' => '2025-01-24',
        'UltimoRiavvio' => '2026-08-21 18:29',
        'GiorniDaRiavvio' => '7',
        'UltimaPatch_Data' => '2026-08-21',
        'UltimaPatch_KB' => 'KB5121003',
        'GiorniDaUltimaPatch' => '7',
        'RiavvioPendente' => 'NO',
        'AV_Prodotto' => 'Microsoft Defender',
        'AV_TerzeParti' => 'nessuno',
        'AV_RealTime' => 'SI',
        'AV_ServizioAttivo' => 'SI',
        'AV_FirmeAggiornateAl' => '2026-08-27',
        'AV_FirmeGiorniEta' => 'N/D',
        'AV_TamperProtection' => 'SI',
        'AV_UltimaScansione' => '2026-08-21',
        'BitLocker_Stato' => 'N/D',
        'BitLocker_Protezione' => 'N/D',
        'BitLocker_Metodo' => 'N/D',
        'BitLocker_Protettori' => 'N/D',
        'BitLocker_RecoveryKeyPresente' => 'NO',
        'BitLocker_DoveECustoditaLaChiave' => '',
        'TPM_Presente' => 'SI',
        'TPM_Pronto' => 'SI',
        'SecureBoot' => 'NO',
        'MembriGruppoAdmin' => 'DESKTOP-L1M3R8T\\Administrator; DESKTOP-L1M3R8T\\franc; DESKTOP-L1M3R8T\\ilsen',
        'AdminBuiltin_Nome' => 'Administrator',
        'AdminBuiltin_Stato' => 'NO - disabilitato',
        'AdminBuiltin_Rinominato' => 'NO',
        'LAPS' => 'NO - password admin locale non gestita',
        'AccountLocaliAttivi' => 'franc; ilsen',
        'Firewall' => 'Domain=ON; Private=ON; Public=ON',
        'RDP' => 'NO',
        'BloccoSchermo' => 'Nessuna policy di macchina - impostazione lasciata allutente',
        'TimeoutVideoAC_sec' => 'N/D',
        'AzureAD_Joined' => 'N/D',
        'Dominio_Joined' => 'N/D',
        'InDominio' => 'NO (workgroup)',
        'MDM_Enrolled' => 'NO',
        'MDM_Url' => 'N/D',
        'NumeroSoftwareInstallati' => '113',
        'AppCritiche' => '7-Zip 21.02; Google Chrome 152.0.7977.64',
        'StrumentiControlloRemoto' => 'Chrome Remote Desktop',
        'OneDrive' => 'in esecuzione',
        'Backup_Tipo' => '',
        'Backup_UltimoOK' => '',
        'Backup_UltimoRestoreTestato' => '',
    ];

    $values = array_merge($values, $overrides);

    return array_map(fn (string $header): string => (string) ($values[$header] ?? ''), inventoryHeader());
}

function importRows(Client $client, array ...$rows): array
{
    return app(EndpointInventoryCsvImporter::class)
        ->importRows([inventoryHeader(), ...$rows], $client->id);
}

beforeEach(function () {
    $this->client = Client::create(['name' => 'Cliente Test', 'asset_prefix' => 'TST']);
    $this->operator = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->operator);
});

it('creates the device and the dated detection from a census row', function () {
    $result = importRows($this->client, inventoryRow());

    expect($result['devices_created'])->toBe(1)
        ->and($result['checks_created'])->toBe(1)
        ->and($result['errors'])->toBe([]);

    $device = Device::first();

    expect($device->hostname)->toBe('DESKTOP-L1M3R8T')
        ->and($device->manufacturer)->toBe('Gigabyte Technology Co., Ltd.')
        ->and($device->inventory_assignee)->toBe('Mario Rossi')
        // "Default string" e' un segnaposto del BIOS: non va salvato come seriale.
        ->and($device->serial_number)->toBeNull();

    $check = $device->securityChecks()->first();

    expect($check->source)->toBe(DeviceSecurityCheck::SOURCE_INVENTORY)
        ->and($check->checked_at->format('Y-m-d H:i'))->toBe('2026-08-28 06:47')
        ->and((float) $check->ram_gb)->toBe(15.9)
        ->and($check->days_since_last_patch)->toBe(7)
        ->and($check->av_tamper_protection)->toBeTrue()
        ->and($check->av_signatures_age_days)->toBeNull()
        ->and($check->reboot_pending)->toBeFalse()
        ->and($check->installed_software_count)->toBe(113)
        ->and($check->domain_membership)->toBe('NO (workgroup)')
        ->and($check->laps)->toBe('NO - password admin locale non gestita')
        ->and($check->raw_row)->toHaveKey('Dischi');
});

it('flags the critical fields that are in a risk state', function () {
    importRows($this->client, inventoryRow());

    $issues = DeviceSecurityCheck::first()->criticalIssues();

    expect(array_keys($issues))
        ->toBe(['os_support', 'admin_group', 'laps', 'bitlocker', 'backup_restore']);

    // franc e' l'utente loggato in questa rilevazione (UtenteLoggato): e' il
    // caso "il dipendente e' admin del proprio PC", non l'anomalia puntuale.
    expect($issues['admin_group']['detail'])->not->toContain('franc')
        ->and($issues['admin_group']['detail'])->toContain('ilsen')
        // Administrator e' in allowlist: non deve comparire fra i segnalati.
        ->and($issues['admin_group']['detail'])->not->toContain('Administrator');
});

it("does not flag the device's own logged-in user as an unexpected local admin", function () {
    // Stesso gruppo admin, ma stavolta e' ilsen ad essere loggato: la
    // segnalazione deve seguire l'utente della rilevazione, non un nome fisso.
    importRows($this->client, inventoryRow(['UtenteLoggato' => 'DESKTOP-L1M3R8T\ilsen']));

    $detail = DeviceSecurityCheck::first()->criticalIssues()['admin_group']['detail'];

    expect($detail)->not->toContain('ilsen')
        ->and($detail)->toContain('franc');
});

it('keeps a supported operating system and an on BitLocker out of the findings', function () {
    importRows($this->client, inventoryRow([
        'OS_Supporto' => 'Supportato fino al 14/10/2031',
        'BitLocker_Protezione' => 'On',
        'MembriGruppoAdmin' => 'PC01\\Administrator',
        'LAPS' => 'SI - password gestita',
        'Backup_UltimoRestoreTestato' => '2026-06-01',
    ]));

    $check = DeviceSecurityCheck::first();

    expect($check->criticalIssues())->toBe([])
        ->and($check->disk_encryption_active)->toBeTrue();
});

it('marks an expired support date as a risk even when it says supportato fino al', function () {
    importRows($this->client, inventoryRow(['OS_Supporto' => 'Supportato fino al 14/10/2025']));

    $issue = DeviceSecurityCheck::first()->criticalIssues()['os_support'];

    expect($issue['detail'])->toBe('Supporto scaduto il 14/10/2025');
});

it('derives the legacy boolean checks and the outcome from the census fields', function () {
    importRows($this->client, inventoryRow());

    $check = DeviceSecurityCheck::first();

    expect($check->os_updated)->toBeTrue()
        ->and($check->antivirus_active)->toBeTrue()
        ->and($check->firewall_active)->toBeTrue()
        ->and($check->disk_encryption_active)->toBeFalse()
        ->and($check->screen_lock_active)->toBeFalse()
        // "NO - disabilitato" = account builtin non attivo, controllo superato.
        ->and($check->admin_user_disabled)->toBeTrue()
        ->and($check->outcome)->toBe(SecurityOutcome::NonCompliant);
});

it('opens findings for the failed checks without duplicating them on the next run', function () {
    importRows($this->client, inventoryRow());
    $first = SecurityFinding::where('status', FindingStatus::Open)->count();

    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-04 07:00']));

    expect($first)->toBeGreaterThan(0)
        ->and(SecurityFinding::where('status', FindingStatus::Open)->count())->toBe($first);
});

it('adds a new detection per run and never overwrites the previous ones', function () {
    importRows($this->client, inventoryRow());
    $result = importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-04 07:00', 'LAPS' => 'SI']));

    expect($result['devices_created'])->toBe(0)
        ->and($result['devices_updated'])->toBe(1)
        ->and($result['checks_created'])->toBe(1)
        ->and(Device::count())->toBe(1)
        ->and(DeviceSecurityCheck::count())->toBe(2);
});

it('is idempotent when the same file is imported twice', function () {
    importRows($this->client, inventoryRow());
    $result = importRows($this->client, inventoryRow());

    expect($result['checks_created'])->toBe(0)
        ->and($result['checks_updated'])->toBe(1)
        ->and(DeviceSecurityCheck::count())->toBe(1);
});

it('follows the same device when the hostname changes but the serial does not', function () {
    importRows($this->client, inventoryRow(['Seriale' => 'PF3ABC12']));
    importRows($this->client, inventoryRow([
        'DataRilevazione' => '2026-09-04 07:00',
        'Seriale' => 'PF3ABC12',
        'Hostname' => 'PC-UFFICIO-04',
    ]));

    expect(Device::count())->toBe(1)
        ->and(Device::first()->hostname)->toBe('PC-UFFICIO-04')
        ->and(DeviceSecurityCheck::count())->toBe(2);
});

it('tracks when a critical field changed state over time', function () {
    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-08-01 08:00']));
    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-01 08:00']));
    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-15 08:00', 'LAPS' => 'SI - gestita']));

    $device = Device::first();
    $history = app(EndpointHistory::class);

    $transitions = $history->transitions($device, 'laps');

    expect($transitions)->toHaveCount(2)
        ->and($transitions[0]['to'])->toBe(DeviceSecurityCheck::STATE_RISK)
        ->and($transitions[1]['from'])->toBe(DeviceSecurityCheck::STATE_RISK)
        ->and($transitions[1]['to'])->toBe(DeviceSecurityCheck::STATE_OK)
        ->and($transitions[1]['at']->format('d/m'))->toBe('15/09');

    // LAPS ora e' a posto, BitLocker e' in rischio da tutte e tre le rilevazioni.
    expect($history->riskStreak($device, 'laps'))->toBe(0)
        ->and($history->riskStreak($device, 'bitlocker'))->toBe(3)
        ->and($history->riskSince($device, 'bitlocker')->format('Y-m-d'))->toBe('2026-08-01');
});

it('reads the delimiter, the BOM and the quoting of the generated file', function () {
    $path = tempnam(sys_get_temp_dir(), 'inventario').'.csv';
    $lines = collect([inventoryHeader(), inventoryRow()])
        ->map(fn (array $row): string => collect($row)
            ->map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"')
            ->implode(';'))
        ->implode("\r\n");

    file_put_contents($path, "\xEF\xBB\xBF".$lines);

    $result = app(EndpointInventoryCsvImporter::class)->import($path, $this->client->id);
    unlink($path);

    expect($result['devices_created'])->toBe(1)
        ->and($result['checks_created'])->toBe(1)
        ->and(Device::first()->hostname)->toBe('DESKTOP-L1M3R8T')
        // Il BOM davanti alla prima intestazione non deve mangiarsi
        // DataRilevazione: senza data la rilevazione non e' collocabile
        // nello storico e il reimport smette di essere idempotente.
        ->and(DeviceSecurityCheck::first()->checked_at->format('Y-m-d H:i'))->toBe('2026-08-28 06:47');
});

it('reports the rows it cannot identify instead of failing the whole import', function () {
    $result = importRows(
        $this->client,
        inventoryRow(['Hostname' => '', 'Seriale' => 'Default string']),
        inventoryRow(),
    );

    expect($result['skipped'])->toBe(1)
        ->and($result['checks_created'])->toBe(1)
        ->and($result['errors'][0])->toContain('Riga 2');
});

it('renders the census pages with the critical badges', function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    importRows($this->client, inventoryRow());
    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-04 07:00', 'LAPS' => 'SI - gestita']));

    $device = Device::first();
    $check = DeviceSecurityCheck::latest('checked_at')->first();

    Livewire::test(ListDeviceSecurityChecks::class)
        ->assertOk()
        ->assertSee('DESKTOP-L1M3R8T');

    Livewire::test(ViewDeviceSecurityCheck::class, ['record' => $check->getKey()])
        ->assertOk()
        ->assertSee('Campi critici')
        ->assertSee('Nessuna protezione BitLocker rilevata');

    // La scheda dispositivo mostra il riepilogo storico dei campi critici.
    Livewire::test(ViewDevice::class, ['record' => $device->getKey()])
        ->assertOk()
        ->assertSee('Sicurezza endpoint')
        ->assertSee('2 rilevazioni in archivio')
        ->assertSee('In questo stato da 2 rilevazioni consecutive');
});

it('lists the state changes of a critical field in the timeline modal', function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-08-01 08:00']));
    importRows($this->client, inventoryRow(['DataRilevazione' => '2026-09-15 08:00', 'LAPS' => 'SI - gestita']));

    $device = Device::first();

    Livewire::test(ViewDevice::class, ['record' => $device->getKey()])
        ->mountAction('securityTimeline')
        ->assertActionMounted('securityTimeline');

    // Il contenuto della modale e' una vista a parte: la si verifica sui dati
    // reali dello storico, non sull'HTML della pagina che la monta.
    $html = view('filament.devices.security-timeline', [
        'timeline' => [
            'laps' => [
                'label' => 'Password admin locale (LAPS)',
                'transitions' => app(EndpointHistory::class)->transitions($device, 'laps'),
            ],
        ],
    ])->render();

    expect($html)->toContain('Password admin locale (LAPS)')
        ->and($html)->toContain('01/08/2026')
        ->and($html)->toContain('15/09/2026')
        ->and($html)->toContain('a posto');
});
