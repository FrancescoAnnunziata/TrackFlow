<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use BelongsToClient, SoftDeletes;

    protected $fillable = [
        'client_id',
        'device_id',
        'opened_by_user_id',
        'assigned_to_user_id',
        'title',
        'description',
        'priority',
        'status',
        'opened_at',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'priority' => TicketPriority::class,
        'status' => TicketStatus::class,
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket): void {
            if (empty($ticket->opened_at)) {
                $ticket->opened_at = now();
            }

            if (empty($ticket->opened_by_user_id) && auth()->check()) {
                $ticket->opened_by_user_id = auth()->id();
            }

            if (empty($ticket->client_id) && $ticket->device_id) {
                $ticket->client_id = Device::whereKey($ticket->device_id)->value('client_id');
            }
        });

        static::saving(function (SupportTicket $ticket): void {
            if ($ticket->status === TicketStatus::Resolved && empty($ticket->resolved_at)) {
                $ticket->resolved_at = now();
            }

            if ($ticket->status === TicketStatus::Closed && empty($ticket->closed_at)) {
                $ticket->closed_at = now();
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }
}
