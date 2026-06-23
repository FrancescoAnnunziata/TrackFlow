<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Quote extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_INVOICED = 'invoiced';

    /**
     * Quanti giorni resta valido un magic link di approvazione.
     */
    public const MAGIC_LINK_DAYS = 14;

    protected $fillable = [
        'user_id',
        'client_id',
        'number',
        'issue_date',
        'description',
        'estimated_hours',
        'hourly_rate',
        'vat_rate',
        'status',
        'sent_at',
        'reminders_sent',
        'accepted_at',
        'accepted_by',
        'invoice_id',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'sent_at' => 'datetime',
        'reminders_sent' => 'integer',
        'accepted_at' => 'datetime',
        'estimated_hours' => 'decimal:1',
        'hourly_rate' => 'decimal:2',
        'vat_rate' => 'decimal:2',
    ];

    /**
     * L'utente (admin) che ha emesso il preventivo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Il referente che ha accettato il preventivo, se accettato.
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * La fattura generata da questo preventivo, se generata.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function taxableAmount(): float
    {
        return round((float) $this->estimated_hours * (float) $this->hourly_rate, 2);
    }

    public function vatAmount(): float
    {
        return round($this->taxableAmount() * ((float) $this->vat_rate / 100), 2);
    }

    public function total(): float
    {
        return round($this->taxableAmount() + $this->vatAmount(), 2);
    }

    /**
     * URL firmato e temporaneo che autentica il referente indicato e lo porta
     * sulla pagina di approvazione del preventivo (magic link, niente password).
     */
    public function magicLinkFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'quote.magic',
            now()->addDays(self::MAGIC_LINK_DAYS),
            ['quote' => $this->getKey(), 'user' => $user->getKey()],
        );
    }
}
