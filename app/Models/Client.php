<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    public const PROVIDER_FIC = 'fatture_in_cloud';

    public const MODEL_FORFAIT = 'forfait';

    public const MODEL_HOURLY = 'hourly';

    public const MODEL_DAILY = 'daily';

    public const TIMING_ARREARS = 'arrears';

    public const TIMING_ADVANCE = 'advance';

    protected $fillable = [
        'name',
        'asset_prefix',
        'logo',
        'notes',
        'entity_type',
        'vat_number',
        'tax_code',
        'address_street',
        'address_postal_code',
        'address_city',
        'address_province',
        'country',
        'country_iso',
        'email',
        'certified_email',
        'ei_code',
        'invoicing_provider',
        'billing_model',
        'billing_period_months',
        'billing_timing',
        'reconcile_previous_period',
        'forfait_amount',
        'forfait_lines',
        'default_hourly_rate',
        'hours_per_day',
        'daily_rate',
        'minimum_hours_per_month',
        'monthly_extra_amount',
        'monthly_extra_label',
        'vat_rate',
        'consulting_label',
        'payment_method_id',
    ];

    protected $casts = [
        'billing_period_months' => 'integer',
        'reconcile_previous_period' => 'boolean',
        'forfait_amount' => 'decimal:2',
        'forfait_lines' => 'array',
        'default_hourly_rate' => 'decimal:2',
        'hours_per_day' => 'decimal:1',
        'daily_rate' => 'decimal:2',
        'minimum_hours_per_month' => 'decimal:2',
        'monthly_extra_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
    ];

    /**
     * True se il cliente è fatturabile da TrackFlow (provider Fatture in Cloud).
     * Gli altri (es. Fiscozen, no API) si fatturano fuori.
     */
    public function isBillableHere(): bool
    {
        return $this->invoicing_provider === self::PROVIDER_FIC;
    }

    /**
     * Tariffa oraria applicabile a un utente su questo cliente: override
     * specifico se presente, altrimenti la tariffa di default.
     */
    public function rateForUser(int $userId): ?float
    {
        $override = $this->userRates->firstWhere('user_id', $userId);

        return (float) ($override->hourly_rate ?? $this->default_hourly_rate);
    }

    public function userRates(): HasMany
    {
        return $this->hasMany(ClientUserRate::class);
    }

    public function hours(): BelongsToMany
    {
        return $this->belongsToMany(Hour::class)->withTimestamps();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Utenti associati a questo cliente tramite pivot (associazione
     * molti-a-molti). Include, dopo il backfill, anche chi ha questo cliente
     * come principale.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')->withTimestamps();
    }

    /**
     * Utenti referente del cliente (ruolo client): destinatari delle
     * comunicazioni indirizzate al cliente (preventivi, solleciti, ...).
     */
    public function contacts(): HasMany
    {
        return $this->users()->where('role', 'client');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function securityChecks(): HasMany
    {
        return $this->hasMany(DeviceSecurityCheck::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class);
    }
}
