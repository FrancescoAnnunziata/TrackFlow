<?php

namespace App\Services\Import;

use App\Enums\DeviceCategory;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceSecurityCheck;
use App\Models\User;
use App\Support\Inventory\InventoryValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa il CSV prodotto dallo script PowerShell di censimento endpoint.
 *
 * Il file ha delimitatore ";", codifica UTF-8 (con BOM) e una riga per
 * dispositivo per ogni giro di censimento. L'import lavora su due livelli:
 *
 *  - Device: anagrafica stabile (hostname, seriale, assegnatario, reparto,
 *    ubicazione, stato ciclo di vita). Viene aggiornata, mai duplicata.
 *  - DeviceSecurityCheck: la rilevazione datata con tutti i campi tecnici.
 *    Ogni import aggiunge righe nuove e lascia intatte quelle precedenti.
 *
 * Il dispositivo e' riconosciuto per Seriale (chiave stabile nel tempo: un PC
 * puo' essere rinominato o reinstallato). Quando il BIOS espone un seriale
 * segnaposto ("Default string" e simili) si ripiega sull'hostname.
 *
 * Ricaricare due volte lo stesso file non duplica nulla: una rilevazione con
 * stesso dispositivo e stessa DataRilevazione viene aggiornata al posto di
 * esserne creata una seconda.
 */
class EndpointInventoryCsvImporter
{
    /**
     * Intestazione CSV => colonna del model, con il tipo da applicare.
     * I campi non elencati finiscono comunque in raw_row.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CHECK_COLUMNS = [
        'RilevatoDa' => ['detected_by', 'text'],
        'EseguitoComeAdmin' => ['ran_as_admin', 'bool'],

        'Hostname' => ['hostname', 'text'],
        'RAM_GB' => ['ram_gb', 'decimal'],
        'Dischi' => ['disks', 'text'],
        'UtenteLoggato' => ['logged_user', 'text'],
        'UltimoUtilizzoProfilo' => ['profile_last_used_at', 'date'],

        'OS' => ['os_name', 'text'],
        'OS_Edizione' => ['os_edition', 'text'],
        'OS_Versione' => ['os_version', 'text'],
        'OS_Build' => ['os_build', 'text'],
        'OS_Architettura' => ['os_architecture', 'text'],
        // Volutamente "raw": il testo integrale ("Supportato fino al ...",
        // "DA VERIFICARE") e' cio' su cui si basa la segnalazione.
        'OS_Supporto' => ['os_support', 'raw'],
        'OS_DataInstallazione' => ['os_installed_at', 'date'],
        'UltimoRiavvio' => ['last_reboot_at', 'date'],
        'GiorniDaRiavvio' => ['days_since_reboot', 'int'],

        'UltimaPatch_Data' => ['last_patch_at', 'date'],
        'UltimaPatch_KB' => ['last_patch_kb', 'text'],
        'GiorniDaUltimaPatch' => ['days_since_last_patch', 'int'],
        'RiavvioPendente' => ['reboot_pending', 'bool'],

        'AV_Prodotto' => ['av_product', 'text'],
        'AV_TerzeParti' => ['av_third_party', 'text'],
        'AV_RealTime' => ['av_realtime', 'bool'],
        'AV_ServizioAttivo' => ['av_service_active', 'bool'],
        'AV_FirmeAggiornateAl' => ['av_signatures_updated_at', 'date'],
        'AV_FirmeGiorniEta' => ['av_signatures_age_days', 'int'],
        'AV_TamperProtection' => ['av_tamper_protection', 'bool'],
        'AV_UltimaScansione' => ['av_last_scan_at', 'date'],

        'BitLocker_Stato' => ['bitlocker_status', 'text'],
        'BitLocker_Protezione' => ['bitlocker_protection', 'text'],
        'BitLocker_Metodo' => ['bitlocker_method', 'text'],
        'BitLocker_Protettori' => ['bitlocker_protectors', 'text'],
        'BitLocker_RecoveryKeyPresente' => ['bitlocker_recovery_key_present', 'bool'],
        'BitLocker_DoveECustoditaLaChiave' => ['bitlocker_key_location', 'text'],
        'TPM_Presente' => ['tpm_present', 'bool'],
        'TPM_Pronto' => ['tpm_ready', 'bool'],
        'SecureBoot' => ['secure_boot', 'bool'],

        'MembriGruppoAdmin' => ['admin_group_members', 'text'],
        'AdminBuiltin_Nome' => ['builtin_admin_name', 'text'],
        'AdminBuiltin_Stato' => ['builtin_admin_status', 'raw'],
        'AdminBuiltin_Rinominato' => ['builtin_admin_renamed', 'bool'],
        'LAPS' => ['laps', 'raw'],
        'AccountLocaliAttivi' => ['local_active_accounts', 'text'],

        'Firewall' => ['firewall_profiles', 'text'],
        'RDP' => ['rdp_enabled', 'bool'],
        'BloccoSchermo' => ['screen_lock_policy', 'raw'],
        'TimeoutVideoAC_sec' => ['screen_timeout_ac_seconds', 'int'],

        'AzureAD_Joined' => ['azure_ad_joined', 'bool'],
        'Dominio_Joined' => ['domain_joined', 'bool'],
        'InDominio' => ['domain_membership', 'raw'],
        'MDM_Enrolled' => ['mdm_enrolled', 'bool'],
        'MDM_Url' => ['mdm_url', 'text'],

        'NumeroSoftwareInstallati' => ['installed_software_count', 'int'],
        'AppCritiche' => ['critical_apps', 'text'],
        'StrumentiControlloRemoto' => ['remote_control_tools', 'text'],
        'OneDrive' => ['onedrive_status', 'text'],

        'Backup_Tipo' => ['backup_type', 'text'],
        'Backup_UltimoOK' => ['backup_last_ok_at', 'date'],
        'Backup_UltimoRestoreTestato' => ['backup_last_restore_test_at', 'date'],
    ];

    /** Valori di StatoCicloVita riconosciuti, mappati sullo stato del device. */
    private const LIFECYCLE_MAP = [
        'in uso' => DeviceStatus::Assigned,
        'assegnato' => DeviceStatus::Assigned,
        'magazzino' => DeviceStatus::InStock,
        'in magazzino' => DeviceStatus::InStock,
        'scorta' => DeviceStatus::InStock,
        'in manutenzione' => DeviceStatus::Maintenance,
        'in riparazione' => DeviceStatus::Repair,
        'dismesso' => DeviceStatus::Disposed,
        'da dismettere' => DeviceStatus::Disposed,
        'smarrito' => DeviceStatus::Lost,
        'riservato' => DeviceStatus::Reserved,
    ];

    /**
     * @return array{devices_created: int, devices_updated: int, checks_created: int, checks_updated: int, skipped: int, findings: int, errors: array<int, string>}
     */
    public function import(string $path, int $clientId, ?int $userId = null): array
    {
        return $this->importRows($this->readRows($path), $clientId, $userId);
    }

    /**
     * @param  array<int, array<int, string>>  $rows  La prima riga e' l'intestazione.
     * @return array{devices_created: int, devices_updated: int, checks_created: int, checks_updated: int, skipped: int, findings: int, errors: array<int, string>}
     */
    public function importRows(array $rows, int $clientId, ?int $userId = null): array
    {
        $result = [
            'devices_created' => 0,
            'devices_updated' => 0,
            'checks_created' => 0,
            'checks_updated' => 0,
            'skipped' => 0,
            'findings' => 0,
            'errors' => [],
        ];

        if (count($rows) < 2) {
            throw new RuntimeException('Il file non contiene righe di censimento.');
        }

        $header = $this->normalizeHeader(array_shift($rows));

        if (! in_array('Hostname', $header, true) && ! in_array('Seriale', $header, true)) {
            throw new RuntimeException('Intestazione non riconosciuta: mancano sia "Hostname" sia "Seriale".');
        }

        $checkedBy = $this->resolveCheckedByUserId($userId);

        foreach ($rows as $index => $row) {
            // Numero di riga come lo vede l'utente nel file (1 = intestazione).
            $line = $index + 2;

            if ($this->isBlankRow($row)) {
                continue;
            }

            try {
                $this->importRow($this->associate($header, $row), $clientId, $checkedBy, $result);
            } catch (Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "Riga {$line}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $data  Riga indicizzata per intestazione.
     * @param  array<string, mixed>  $result
     */
    private function importRow(array $data, int $clientId, int $checkedBy, array &$result): void
    {
        $hostname = InventoryValue::text($data['Hostname'] ?? null);
        $serial = $this->usableSerial($data['Seriale'] ?? null);

        if ($hostname === null && $serial === null) {
            throw new RuntimeException('nessun Hostname e nessun Seriale utilizzabile, impossibile identificare il dispositivo.');
        }

        $checkedAt = InventoryValue::date($data['DataRilevazione'] ?? null) ?? Carbon::now();

        DB::transaction(function () use ($data, $clientId, $checkedBy, $hostname, $serial, $checkedAt, &$result): void {
            $device = $this->resolveDevice($clientId, $serial, $hostname);

            if ($device === null) {
                $device = Device::create([
                    'client_id' => $clientId,
                    'name' => $hostname ?? $serial,
                    'hostname' => $hostname,
                    'serial_number' => $serial,
                    'category' => DeviceCategory::IT,
                    'manufacturer' => InventoryValue::text($data['Produttore'] ?? null),
                    'model' => InventoryValue::text($data['Modello'] ?? null),
                ]);

                $result['devices_created']++;
            } else {
                $result['devices_updated']++;
            }

            $this->updateDeviceProfile($device, $data, $hostname, $serial);

            $check = $this->buildCheck($device, $data, $checkedBy, $checkedAt, $result);

            $before = $check->findings()->count();
            $check->generateFindingsForFailures();
            $result['findings'] += max(0, $check->findings()->count() - $before);
        });
    }

    /**
     * Riconosce il dispositivo prima dal seriale (chiave stabile), poi
     * dall'hostname. Se lo trova per hostname e non aveva un seriale, glielo
     * assegna: dal giro successivo l'aggancio e' sul seriale anche se il PC
     * viene rinominato.
     */
    private function resolveDevice(int $clientId, ?string $serial, ?string $hostname): ?Device
    {
        $query = fn (): Builder => Device::query()->where('client_id', $clientId);

        if ($serial !== null) {
            $bySerial = $query()->whereRaw('LOWER(serial_number) = ?', [Str::lower($serial)])->first();

            if ($bySerial !== null) {
                return $bySerial;
            }
        }

        if ($hostname === null) {
            return null;
        }

        $byHostname = $query()
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(hostname) = ?', [Str::lower($hostname)])
                ->orWhereRaw('LOWER(name) = ?', [Str::lower($hostname)]))
            ->first();

        // Un dispositivo gia' agganciato a un altro seriale e' un'altra
        // macchina che ha ereditato il nome: si crea un record nuovo.
        if ($byHostname !== null && $serial !== null && filled($byHostname->serial_number)
            && Str::lower($byHostname->serial_number) !== Str::lower($serial)) {
            return null;
        }

        return $byHostname;
    }

    /**
     * Aggiorna l'anagrafica stabile. I campi manuali del CSV (assegnatario,
     * reparto, ubicazione, ciclo di vita, note) sovrascrivono solo quando sono
     * compilati: lasciarli vuoti nello script non cancella quanto gia' inserito
     * dalla webapp.
     *
     * @param  array<string, string>  $data
     */
    private function updateDeviceProfile(Device $device, array $data, ?string $hostname, ?string $serial): void
    {
        $attributes = array_filter([
            'hostname' => $hostname,
            'serial_number' => $serial,
            'manufacturer' => InventoryValue::text($data['Produttore'] ?? null),
            'model' => InventoryValue::text($data['Modello'] ?? null),
            'inventory_assignee' => InventoryValue::text($data['Assegnatario'] ?? null),
            'department' => InventoryValue::text($data['Reparto'] ?? null),
            'location' => InventoryValue::text($data['Ubicazione'] ?? null),
            'lifecycle_stage' => InventoryValue::text($data['StatoCicloVita'] ?? null),
            'notes' => InventoryValue::text($data['Note'] ?? null),
        ], fn ($value): bool => $value !== null);

        if (($assignee = $attributes['inventory_assignee'] ?? null) !== null) {
            $user = $this->matchUser($device->client_id, $assignee);

            if ($user !== null) {
                $attributes['assigned_user_id'] = $user->id;
            }
        }

        if (($lifecycle = $attributes['lifecycle_stage'] ?? null) !== null) {
            $status = self::LIFECYCLE_MAP[Str::lower($lifecycle)] ?? null;

            if ($status !== null) {
                $attributes['status'] = $status;
            }
        }

        $device->fill($attributes)->save();
    }

    /**
     * Cerca l'utente corrispondente all'Assegnatario scritto nel CSV, fra
     * quelli del cliente. Nessuna corrispondenza: resta solo il testo libero in
     * inventory_assignee, senza forzare assegnazioni sbagliate.
     */
    private function matchUser(int $clientId, string $assignee): ?User
    {
        $needle = Str::lower(trim($assignee));

        return User::query()
            ->where(fn ($q) => $q->where('client_id', $clientId)
                ->orWhereHas('clients', fn ($c) => $c->where('clients.id', $clientId)))
            ->get()
            ->first(fn (User $user): bool => Str::lower(trim($user->full_name)) === $needle
                || Str::lower(trim($user->name)) === $needle);
    }

    /**
     * Crea (o riallinea, se il file viene ricaricato) la rilevazione datata.
     *
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $result
     */
    private function buildCheck(Device $device, array $data, int $checkedBy, Carbon $checkedAt, array &$result): DeviceSecurityCheck
    {
        $check = DeviceSecurityCheck::query()
            ->where('device_id', $device->id)
            ->where('source', DeviceSecurityCheck::SOURCE_INVENTORY)
            ->where('checked_at', $checkedAt)
            ->first();

        if ($check === null) {
            $check = new DeviceSecurityCheck;
            $result['checks_created']++;
        } else {
            $result['checks_updated']++;
        }

        $check->fill($this->mapCheckAttributes($data));

        $check->client_id = $device->client_id;
        $check->device_id = $device->id;
        $check->checked_by_user_id = $checkedBy;
        $check->checked_at = $checkedAt;
        $check->source = DeviceSecurityCheck::SOURCE_INVENTORY;
        $check->raw_row = $data;

        $check->applyDerivedChecks();
        $check->save();

        return $check;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function mapCheckAttributes(array $data): array
    {
        $attributes = [];

        foreach (self::CHECK_COLUMNS as $header => [$column, $type]) {
            $value = $data[$header] ?? null;

            $attributes[$column] = match ($type) {
                'bool' => InventoryValue::bool($value),
                'int' => InventoryValue::int($value),
                'decimal' => InventoryValue::decimal($value),
                'date' => InventoryValue::date($value),
                // "raw" conserva il testo completo, anche quando e' un "NO ..."
                // motivato: e' il valore su cui si basano le segnalazioni.
                'raw' => blank(trim((string) $value)) ? null : trim((string) $value),
                default => InventoryValue::text($value),
            };
        }

        return $attributes;
    }

    /**
     * Il seriale e' la chiave stabile, ma molti BIOS scrivono un segnaposto
     * ("Default string", "To Be Filled By O.E.M."): in quel caso vale come
     * assente e il riconoscimento passa dall'hostname.
     */
    private function usableSerial(?string $value): ?string
    {
        $serial = InventoryValue::text($value);

        if ($serial === null) {
            return null;
        }

        $placeholders = array_map(
            fn (string $item): string => mb_strtolower($item),
            (array) config('inventario_endpoint.serial_placeholders', [])
        );

        return in_array(mb_strtolower($serial), $placeholders, true) ? null : $serial;
    }

    private function resolveCheckedByUserId(?int $userId): int
    {
        $resolved = $userId ?? auth()->id() ?? User::query()->where('role', 'admin')->value('id') ?? User::query()->value('id');

        if ($resolved === null) {
            throw new RuntimeException('Nessun utente a cui attribuire le rilevazioni importate.');
        }

        return (int) $resolved;
    }

    /**
     * Legge il CSV: delimitatore ";", UTF-8 con BOM (che va tolto o la prima
     * intestazione non corrisponde).
     *
     * @return array<int, array<int, string>>
     */
    public function readRows(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Impossibile aprire il file CSV.');
        }

        // Il BOM va tolto PRIMA di fgetcsv: se resta davanti alla prima cella,
        // la virgoletta di apertura non e' piu' a inizio campo e fgetcsv
        // restituisce l'intestazione con gli apici dentro il valore.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = (string) config('inventario_endpoint.csv.delimiter', ';');
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(fn ($value): string => (string) $value, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>  $header
     * @return array<int, string>
     */
    private function normalizeHeader(array $header): array
    {
        return array_map(
            fn (string $name): string => trim(preg_replace('/^\x{FEFF}/u', '', $name), " \t\n\r\0\x0B\"\u{FEFF}"),
            $header
        );
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $row
     * @return array<string, string>
     */
    private function associate(array $header, array $row): array
    {
        $data = [];

        foreach ($header as $position => $name) {
            if ($name !== '') {
                $data[$name] = $row[$position] ?? '';
            }
        }

        return $data;
    }

    /** @param array<int, string> $row */
    private function isBlankRow(array $row): bool
    {
        return trim(implode('', $row)) === '';
    }
}
