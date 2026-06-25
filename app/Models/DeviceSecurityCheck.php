<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceSecurityCheck extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id',
        'device_id',
        'checked_by_user_id',
        'checked_at',
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
     * Crea una SecurityFinding aperta per ogni controllo fallito (valore false).
     * I controlli nullable (non valutati) vengono ignorati.
     */
    public function generateFindingsForFailures(): void
    {
        foreach (self::FINDING_MAP as $field => [$title, $severity]) {
            if ($this->{$field} === false) {
                $this->findings()->create([
                    'client_id' => $this->client_id,
                    'device_id' => $this->device_id,
                    'title' => $title,
                    'severity' => $severity,
                    'status' => FindingStatus::Open,
                ]);
            }
        }
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
