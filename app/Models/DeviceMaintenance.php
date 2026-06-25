<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMaintenance extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id',
        'device_id',
        'performed_by_user_id',
        'maintenance_date',
        'type',
        'description',
        'cost',
        'supplier',
        'next_maintenance_at',
        'notes',
    ];

    protected $casts = [
        'type' => MaintenanceType::class,
        'maintenance_date' => 'date',
        'next_maintenance_at' => 'date',
        'cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (DeviceMaintenance $maintenance): void {
            if (empty($maintenance->client_id) && $maintenance->device_id) {
                $maintenance->client_id = Device::whereKey($maintenance->device_id)->value('client_id');
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
