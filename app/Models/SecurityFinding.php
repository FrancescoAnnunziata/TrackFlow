<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityFinding extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id',
        'device_id',
        'device_security_check_id',
        'title',
        'description',
        'severity',
        'status',
        'due_date',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected $casts = [
        'severity' => FindingSeverity::class,
        'status' => FindingStatus::class,
        'due_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SecurityFinding $finding): void {
            if (empty($finding->client_id) && $finding->device_id) {
                $finding->client_id = Device::whereKey($finding->device_id)->value('client_id');
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function securityCheck(): BelongsTo
    {
        return $this->belongsTo(DeviceSecurityCheck::class, 'device_security_check_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
