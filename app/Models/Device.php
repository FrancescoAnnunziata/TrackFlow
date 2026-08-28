<?php

namespace App\Models;

use App\Enums\DeviceCategory;
use App\Enums\DeviceStatus;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Device extends Model
{
    use BelongsToClient, SoftDeletes;

    protected $fillable = [
        'client_id',
        'asset_code',
        'barcode',
        'qr_token',
        'name',
        'hostname',
        'category',
        'type',
        'manufacturer',
        'model',
        'serial_number',
        'purchase_date',
        'purchase_price',
        'supplier',
        'invoice_number',
        'warranty_until',
        'assigned_user_id',
        'inventory_assignee',
        'location',
        'department',
        'status',
        'lifecycle_stage',
        'notes',
        'next_maintenance_at',
        'attachments',
    ];

    protected $casts = [
        'category' => DeviceCategory::class,
        'status' => DeviceStatus::class,
        'purchase_date' => 'date',
        'warranty_until' => 'date',
        'next_maintenance_at' => 'date',
        'purchase_price' => 'decimal:2',
        'attachments' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Device $device): void {
            if (empty($device->qr_token)) {
                $device->qr_token = (string) Str::uuid();
            }

            if (empty($device->asset_code) && $device->client_id) {
                $device->asset_code = static::generateAssetCode((int) $device->client_id);
            }

            if (empty($device->barcode)) {
                $device->barcode = $device->asset_code;
            }
        });
    }

    /**
     * Genera un asset_code progressivo per cliente: G8-{PREFIX}-{NNNN}.
     * Il progressivo e' calcolato in transazione con lock per evitare collisioni.
     */
    public static function generateAssetCode(int $clientId): string
    {
        return DB::transaction(function () use ($clientId) {
            $client = Client::find($clientId);
            $prefix = static::resolvePrefix($client);

            $last = static::withTrashed()
                ->where('client_id', $clientId)
                ->where('asset_code', 'like', "G8-{$prefix}-%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->pluck('asset_code')
                ->map(fn (string $code): int => (int) Str::afterLast($code, '-'))
                ->max();

            $next = ($last ?? 0) + 1;

            return sprintf('G8-%s-%04d', $prefix, $next);
        });
    }

    public static function resolvePrefix(?Client $client): string
    {
        if ($client && ! empty($client->asset_prefix)) {
            return strtoupper(Str::of($client->asset_prefix)->replaceMatches('/[^A-Za-z0-9]/', ''));
        }

        if ($client && ! empty($client->name)) {
            $letters = strtoupper(Str::of($client->name)->replaceMatches('/[^A-Za-z]/', ''));

            if (strlen($letters) >= 3) {
                return substr($letters, 0, 3);
            }
        }

        return 'C'.($client?->id ?? 0);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(DeviceMaintenance::class);
    }

    public function tickets(): HasMany
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

    public function latestSecurityCheck(): HasOne
    {
        return $this->hasOne(DeviceSecurityCheck::class)->latestOfMany('checked_at');
    }

    /**
     * Rilevazioni del censimento endpoint in ordine cronologico: e' la serie su
     * cui si legge l'evoluzione dei campi critici nel tempo.
     */
    public function inventoryChecks(): HasMany
    {
        return $this->hasMany(DeviceSecurityCheck::class)
            ->where('source', DeviceSecurityCheck::SOURCE_INVENTORY)
            ->orderBy('checked_at');
    }

    /**
     * Assegna il dispositivo a un utente: chiude l'eventuale assegnazione
     * aperta, ne crea una nuova e aggiorna stato/assegnatario sul device.
     */
    public function assignTo(User $user, ?string $notes = null): void
    {
        DB::transaction(function () use ($user, $notes): void {
            $this->assignments()
                ->whereNull('returned_at')
                ->update(['returned_at' => now()]);

            $this->assignments()->create([
                'client_id' => $this->client_id,
                'user_id' => $user->id,
                'assigned_at' => now(),
                'notes' => $notes,
            ]);

            $this->forceFill([
                'assigned_user_id' => $user->id,
                'status' => DeviceStatus::Assigned,
            ])->save();
        });
    }

    /**
     * Registra il rientro del dispositivo: chiude l'assegnazione aperta e
     * riporta il device in magazzino.
     */
    public function returnFromUser(?string $notes = null): void
    {
        DB::transaction(function () use ($notes): void {
            $this->assignments()
                ->whereNull('returned_at')
                ->update([
                    'returned_at' => now(),
                    'notes' => $notes,
                ]);

            $this->forceFill([
                'assigned_user_id' => null,
                'status' => DeviceStatus::InStock,
            ])->save();
        });
    }
}
