<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use App\Models\Concerns\BelongsToClient;
use App\Support\Inventory\InventoryValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una rilevazione dello stato di sicurezza di un dispositivo.
 *
 * Ogni riga e' una fotografia datata (checked_at): il form manuale ne crea una,
 * ogni giro del censimento PowerShell ne aggiunge una per dispositivo. Le righe
 * precedenti non vengono mai sovrascritte, cosi' resta leggibile l'evoluzione
 * nel tempo (vedi App\Services\Security\EndpointHistory).
 */
class DeviceSecurityCheck extends Model
{
    use BelongsToClient;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_INVENTORY = 'inventory_csv';

    protected $fillable = [
        'client_id',
        'device_id',
        'checked_by_user_id',
        'checked_at',
        'source',
        'detected_by',
        'ran_as_admin',
        'os_updated',
        'antivirus_active',
        'antivirus_updated',
        'firewall_active',
        'disk_encryption_active',
        'screen_lock_active',
        'admin_user_disabled',
        'mfa_enabled',
        'backup_configured',
        'usb_policy_ok',
        'password_policy_ok',
        'risk_level',
        'outcome',
        'notes',
        'next_check_at',
        // Censimento endpoint: identita'
        'hostname',
        'ram_gb',
        'disks',
        'logged_user',
        'profile_last_used_at',
        // Sistema operativo
        'os_name',
        'os_edition',
        'os_version',
        'os_build',
        'os_architecture',
        'os_support',
        'os_installed_at',
        'last_reboot_at',
        'days_since_reboot',
        // Patch
        'last_patch_at',
        'last_patch_kb',
        'days_since_last_patch',
        'reboot_pending',
        // Antivirus
        'av_product',
        'av_third_party',
        'av_realtime',
        'av_service_active',
        'av_signatures_updated_at',
        'av_signatures_age_days',
        'av_tamper_protection',
        'av_last_scan_at',
        // Cifratura
        'bitlocker_status',
        'bitlocker_protection',
        'bitlocker_method',
        'bitlocker_protectors',
        'bitlocker_recovery_key_present',
        'bitlocker_key_location',
        'tpm_present',
        'tpm_ready',
        'secure_boot',
        // Account
        'admin_group_members',
        'builtin_admin_name',
        'builtin_admin_status',
        'builtin_admin_renamed',
        'laps',
        'local_active_accounts',
        // Rete
        'firewall_profiles',
        'rdp_enabled',
        'screen_lock_policy',
        'screen_timeout_ac_seconds',
        // Gestione
        'azure_ad_joined',
        'domain_joined',
        'domain_membership',
        'mdm_enrolled',
        'mdm_url',
        // Software
        'installed_software_count',
        'critical_apps',
        'remote_control_tools',
        'onedrive_status',
        // Backup
        'backup_type',
        'backup_last_ok_at',
        'backup_last_restore_test_at',
        'raw_row',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'next_check_at' => 'date',
        'risk_level' => SecurityRiskLevel::class,
        'outcome' => SecurityOutcome::class,
        'os_updated' => 'boolean',
        'antivirus_active' => 'boolean',
        'antivirus_updated' => 'boolean',
        'firewall_active' => 'boolean',
        'disk_encryption_active' => 'boolean',
        'screen_lock_active' => 'boolean',
        'admin_user_disabled' => 'boolean',
        'mfa_enabled' => 'boolean',
        'backup_configured' => 'boolean',
        'usb_policy_ok' => 'boolean',
        'password_policy_ok' => 'boolean',
        'ran_as_admin' => 'boolean',
        'ram_gb' => 'decimal:2',
        'profile_last_used_at' => 'date',
        'os_installed_at' => 'date',
        'last_reboot_at' => 'datetime',
        'days_since_reboot' => 'integer',
        'last_patch_at' => 'date',
        'days_since_last_patch' => 'integer',
        'reboot_pending' => 'boolean',
        'av_realtime' => 'boolean',
        'av_service_active' => 'boolean',
        'av_signatures_updated_at' => 'date',
        'av_signatures_age_days' => 'integer',
        'av_tamper_protection' => 'boolean',
        'av_last_scan_at' => 'date',
        'bitlocker_recovery_key_present' => 'boolean',
        'tpm_present' => 'boolean',
        'tpm_ready' => 'boolean',
        'secure_boot' => 'boolean',
        'builtin_admin_renamed' => 'boolean',
        'rdp_enabled' => 'boolean',
        'screen_timeout_ac_seconds' => 'integer',
        'azure_ad_joined' => 'boolean',
        'domain_joined' => 'boolean',
        'mdm_enrolled' => 'boolean',
        'installed_software_count' => 'integer',
        'backup_last_ok_at' => 'date',
        'backup_last_restore_test_at' => 'date',
        'raw_row' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (DeviceSecurityCheck $check): void {
            if (empty($check->checked_at)) {
                $check->checked_at = now();
            }

            if (empty($check->checked_by_user_id) && auth()->check()) {
                $check->checked_by_user_id = auth()->id();
            }

            if (empty($check->client_id) && $check->device_id) {
                $check->client_id = Device::whereKey($check->device_id)->value('client_id');
            }
        });
    }

    /**
     * Mappa controllo => [etichetta finding, severita'] usata per generare
     * automaticamente le criticita' quando un controllo non e' superato.
     */
    public const FINDING_MAP = [
        'os_updated' => ['Sistema operativo non aggiornato', FindingSeverity::High],
        'antivirus_active' => ['Antivirus non attivo', FindingSeverity::Critical],
        'antivirus_updated' => ['Antivirus non aggiornato', FindingSeverity::High],
        'firewall_active' => ['Firewall non attivo', FindingSeverity::High],
        'disk_encryption_active' => ['Cifratura del disco non attiva', FindingSeverity::Critical],
        'screen_lock_active' => ['Blocco schermo non attivo', FindingSeverity::Medium],
        'admin_user_disabled' => ['Utente amministratore locale non disabilitato', FindingSeverity::Medium],
        'mfa_enabled' => ['MFA non abilitata', FindingSeverity::High],
        'backup_configured' => ['Backup non configurato', FindingSeverity::High],
        'usb_policy_ok' => ['Policy USB non conforme', FindingSeverity::Medium],
        'password_policy_ok' => ['Policy password non conforme', FindingSeverity::Medium],
    ];

    /**
     * Campi del censimento che vengono valutati singolarmente e segnalati
     * quando sono in stato di rischio: chiave => [etichetta, severita'].
     *
     * La chiave e' anche l'identificativo usato per leggere l'andamento nel
     * tempo (EndpointHistory), quindi va mantenuta stabile.
     */
    public const CRITICAL_CHECKS = [
        'os_support' => ['Supporto sistema operativo', FindingSeverity::High],
        'admin_group' => ['Amministratori locali', FindingSeverity::High],
        'laps' => ['Password admin locale (LAPS)', FindingSeverity::High],
        'bitlocker' => ['Protezione BitLocker', FindingSeverity::Critical],
        'av_tamper' => ['Tamper Protection antivirus', FindingSeverity::High],
        'backup_restore' => ['Test di restore del backup', FindingSeverity::Medium],
    ];

    public const STATE_OK = 'ok';

    public const STATE_RISK = 'risk';

    public const STATE_UNKNOWN = 'unknown';

    /**
     * Stato di un campo critico: 'ok', 'risk' oppure 'unknown' quando il dato
     * non e' stato rilevato (tipicamente perche' lo script non girava con
     * privilegi di amministratore).
     *
     * @return array{state: string, detail: ?string}
     */
    public function evaluateCritical(string $key): array
    {
        // I campi critici arrivano dal censimento: su una verifica compilata a
        // mano non esistono, e l'assenza non va letta come rischio.
        if (! $this->isFromInventory()) {
            return ['state' => self::STATE_UNKNOWN, 'detail' => null];
        }

        return match ($key) {
            'os_support' => $this->evaluateOsSupport(),
            'admin_group' => $this->evaluateAdminGroup(),
            'laps' => $this->evaluateLaps(),
            'bitlocker' => $this->evaluateBitLocker(),
            'av_tamper' => $this->evaluateTamperProtection(),
            'backup_restore' => $this->evaluateBackupRestore(),
            default => ['state' => self::STATE_UNKNOWN, 'detail' => null],
        };
    }

    public function isFromInventory(): bool
    {
        return $this->source === self::SOURCE_INVENTORY;
    }

    public function criticalState(string $key): string
    {
        return $this->evaluateCritical($key)['state'];
    }

    /**
     * Tutti i campi critici in stato di rischio.
     *
     * @return array<string, array{label: string, detail: ?string, severity: FindingSeverity}>
     */
    public function criticalIssues(): array
    {
        $issues = [];

        foreach (self::CRITICAL_CHECKS as $key => [$label, $severity]) {
            $result = $this->evaluateCritical($key);

            if ($result['state'] === self::STATE_RISK) {
                $issues[$key] = [
                    'label' => $label,
                    'detail' => $result['detail'],
                    'severity' => $severity,
                ];
            }
        }

        return $issues;
    }

    /** Campi critici non valutabili con i dati presenti nella rilevazione. */
    public function unknownCriticalKeys(): array
    {
        return collect(array_keys(self::CRITICAL_CHECKS))
            ->filter(fn (string $key): bool => $this->criticalState($key) === self::STATE_UNKNOWN)
            ->values()
            ->all();
    }

    /**
     * "Supportato fino al 14/10/2026" e' l'unico valore accettabile, e solo se
     * la data non e' gia' passata. Qualunque altro testo ("DA VERIFICARE",
     * "FUORI SUPPORTO") e' rischio.
     */
    private function evaluateOsSupport(): array
    {
        $value = $this->os_support;

        if (blank($value)) {
            return ['state' => self::STATE_UNKNOWN, 'detail' => null];
        }

        if (preg_match('/^\s*supportato\s+fino\s+al\s*:?\s*(.+)$/iu', $value, $matches) === 1) {
            $until = InventoryValue::date(trim($matches[1]));

            if ($until !== null && $until->isPast()) {
                return ['state' => self::STATE_RISK, 'detail' => 'Supporto scaduto il '.$until->format('d/m/Y')];
            }

            return ['state' => self::STATE_OK, 'detail' => $value];
        }

        return ['state' => self::STATE_RISK, 'detail' => $value];
    }

    /**
     * Nel gruppo Administrators locale devono comparire solo:
     *  - gli account IT ammessi (config inventario_endpoint.admin_group_allowlist);
     *  - l'utente che sta usando il PC in QUESTA rilevazione (UtenteLoggato).
     *
     * Il confronto con l'utente loggato, non con l'assegnatario "anagrafico"
     * (Assegnatario e' un nome umano, "Mario Rossi", mentre il gruppo admin
     * elenca account Windows, "franc": non sono testualmente confrontabili),
     * copre il caso diffuso "il dipendente e' amministratore del proprio PC":
     * e' una pratica di per se' a rischio, ma non e' l'anomalia puntuale che
     * questo controllo vuole far emergere (un account estraneo, un ex
     * dipendente, un servizio dimenticato). Il dettaglio completo del gruppo
     * resta comunque visibile in admin_group_members.
     */
    private function evaluateAdminGroup(): array
    {
        $members = InventoryValue::list($this->admin_group_members);

        if ($members === []) {
            return ['state' => self::STATE_UNKNOWN, 'detail' => null];
        }

        $allowlist = array_map(
            fn (string $name): string => mb_strtolower(trim($name)),
            (array) config('inventario_endpoint.admin_group_allowlist', [])
        );

        $primaryUser = filled($this->logged_user)
            ? mb_strtolower(InventoryValue::accountName($this->logged_user))
            : null;

        $unexpected = collect($members)
            ->reject(function (string $member) use ($allowlist, $primaryUser): bool {
                $name = mb_strtolower(InventoryValue::accountName($member));

                return in_array($name, $allowlist, true) || ($primaryUser !== null && $name === $primaryUser);
            })
            ->values();

        if ($unexpected->isEmpty()) {
            return ['state' => self::STATE_OK, 'detail' => null];
        }

        return [
            'state' => self::STATE_RISK,
            'detail' => 'Account non previsti: '.$unexpected->implode(', '),
        ];
    }

    private function evaluateLaps(): array
    {
        $value = $this->laps;

        if (blank($value)) {
            return ['state' => self::STATE_UNKNOWN, 'detail' => null];
        }

        return InventoryValue::bool($value) === true
            ? ['state' => self::STATE_OK, 'detail' => $value]
            : ['state' => self::STATE_RISK, 'detail' => $value];
    }

    /**
     * La protezione deve risultare "On". Se il dato manca ma lo script NON
     * girava come amministratore il valore non e' leggibile: resta unknown,
     * altrimenti l'assenza di protezione e' un rischio reale.
     */
    private function evaluateBitLocker(): array
    {
        $value = $this->bitlocker_protection;

        if (blank($value)) {
            return $this->ran_as_admin === false
                ? ['state' => self::STATE_UNKNOWN, 'detail' => null]
                : ['state' => self::STATE_RISK, 'detail' => 'Nessuna protezione BitLocker rilevata'];
        }

        return Str::lower(trim($value)) === 'on'
            ? ['state' => self::STATE_OK, 'detail' => $value]
            : ['state' => self::STATE_RISK, 'detail' => $value];
    }

    private function evaluateTamperProtection(): array
    {
        return match ($this->av_tamper_protection) {
            true => ['state' => self::STATE_OK, 'detail' => null],
            false => ['state' => self::STATE_RISK, 'detail' => 'Tamper Protection disattivata'],
            default => ['state' => self::STATE_UNKNOWN, 'detail' => null],
        };
    }

    /** Campo compilato a mano: se vuoto, il restore non e' mai stato provato. */
    private function evaluateBackupRestore(): array
    {
        if ($this->backup_last_restore_test_at === null) {
            return ['state' => self::STATE_RISK, 'detail' => 'Nessun test di restore registrato'];
        }

        return [
            'state' => self::STATE_OK,
            'detail' => 'Ultimo test il '.$this->backup_last_restore_test_at->format('d/m/Y'),
        ];
    }

    /**
     * Ricalcola i controlli booleani storici partendo dai campi del censimento,
     * cosi' badge, filtri e criticita' gia' esistenti continuano a funzionare
     * anche sulle rilevazioni importate. I campi non deducibili dal CSV
     * (MFA, policy USB) restano null = non valutati.
     */
    public function applyDerivedChecks(): void
    {
        $thresholds = (array) config('inventario_endpoint.thresholds', []);

        $this->os_updated = $this->days_since_last_patch === null
            ? null
            : $this->days_since_last_patch <= (int) ($thresholds['max_days_since_patch'] ?? 35);

        $this->antivirus_active = ($this->av_realtime === null && $this->av_service_active === null)
            ? null
            : ($this->av_realtime !== false && $this->av_service_active !== false);

        $this->antivirus_updated = $this->av_signatures_age_days === null
            ? null
            : $this->av_signatures_age_days <= (int) ($thresholds['max_av_signature_age_days'] ?? 7);

        $this->firewall_active = $this->firewallAllProfilesOn();

        $this->disk_encryption_active = $this->criticalState('bitlocker') === self::STATE_OK;

        $this->screen_lock_active = $this->screenLockEnforced();

        // AdminBuiltin_Stato riporta lo stato dell'account: "NO - disabilitato"
        // significa account non attivo, quindi controllo superato.
        $this->admin_user_disabled = blank($this->builtin_admin_status)
            ? null
            : Str::contains(Str::lower($this->builtin_admin_status), ['disabilit', 'disabled']);

        $this->backup_configured = blank($this->backup_type)
            ? null
            : ! Str::contains(Str::lower($this->backup_type), ['nessuno', 'assente', 'no backup']);

        $this->applyOutcomeFromChecks();
    }

    /**
     * Deriva rischio ed esito dai controlli falliti e dai campi critici in
     * stato di rischio. Le voci "non valutate" (null) non pesano.
     */
    public function applyOutcomeFromChecks(): void
    {
        $severities = collect(self::FINDING_MAP)
            ->filter(fn (array $entry, string $field): bool => $this->{$field} === false)
            ->map(fn (array $entry): FindingSeverity => $entry[1])
            ->values()
            ->merge(collect($this->criticalIssues())->pluck('severity'));

        if ($severities->isEmpty()) {
            $this->risk_level = SecurityRiskLevel::Low;
            $this->outcome = SecurityOutcome::Compliant;

            return;
        }

        $worst = $severities
            ->map(fn (FindingSeverity $severity): int => match ($severity) {
                FindingSeverity::Critical => 4,
                FindingSeverity::High => 3,
                FindingSeverity::Medium => 2,
                default => 1,
            })
            ->max();

        $this->risk_level = match ($worst) {
            4 => SecurityRiskLevel::Critical,
            3 => SecurityRiskLevel::High,
            2 => SecurityRiskLevel::Medium,
            default => SecurityRiskLevel::Low,
        };

        $this->outcome = $worst >= 3 ? SecurityOutcome::NonCompliant : SecurityOutcome::Warning;
    }

    /** true solo se tutti i profili firewall elencati risultano ON. */
    private function firewallAllProfilesOn(): ?bool
    {
        $profiles = InventoryValue::list($this->firewall_profiles);

        if ($profiles === []) {
            return null;
        }

        foreach ($profiles as $profile) {
            if (! Str::contains(Str::lower($profile), '=on')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Il blocco schermo conta come attivo solo se esiste una policy di macchina
     * con un timeout > 0: "Nessuna policy di macchina" lascia la scelta
     * all'utente e non e' un controllo superato.
     */
    private function screenLockEnforced(): ?bool
    {
        if (blank($this->screen_lock_policy)) {
            return null;
        }

        if (Str::contains(Str::lower($this->screen_lock_policy), ['nessuna policy', 'non configurat', 'lasciata all'])) {
            return false;
        }

        return $this->screen_timeout_ac_seconds === null
            ? true
            : $this->screen_timeout_ac_seconds > 0;
    }

    /**
     * Crea una SecurityFinding aperta per ogni controllo fallito (valore false)
     * e per ogni campo critico del censimento in stato di rischio.
     * I controlli nullable (non valutati) vengono ignorati.
     */
    public function generateFindingsForFailures(): void
    {
        foreach (self::FINDING_MAP as $field => [$title, $severity]) {
            if ($this->{$field} === false) {
                $this->createFinding($title, $severity);
            }
        }

        foreach ($this->criticalIssues() as $issue) {
            $this->createFinding($issue['label'], $issue['severity'], $issue['detail']);
        }
    }

    /**
     * Evita di riaprire la stessa criticita' ad ogni giro di censimento: se sul
     * dispositivo ne esiste gia' una aperta con lo stesso titolo, aggiorna solo
     * il dettaglio e la rilevazione di riferimento.
     */
    private function createFinding(string $title, FindingSeverity $severity, ?string $detail = null): void
    {
        $existing = SecurityFinding::query()
            ->where('device_id', $this->device_id)
            ->where('title', $title)
            ->where('status', FindingStatus::Open)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'device_security_check_id' => $this->id,
                'description' => $detail ?? $existing->description,
                'severity' => $severity,
            ]);

            return;
        }

        $this->findings()->create([
            'client_id' => $this->client_id,
            'device_id' => $this->device_id,
            'title' => $title,
            'description' => $detail,
            'severity' => $severity,
            'status' => FindingStatus::Open,
        ]);
    }

    public function scopeFromInventory(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_INVENTORY);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class);
    }
}
