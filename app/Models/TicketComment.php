<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketComment extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id',
        'support_ticket_id',
        'user_id',
        'body',
        'internal_note',
    ];

    protected $casts = [
        'internal_note' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (TicketComment $comment): void {
            if (empty($comment->user_id) && auth()->check()) {
                $comment->user_id = auth()->id();
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
