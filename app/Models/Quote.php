<?php

namespace App\Models;

use App\Support\Emittente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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

    /**
     * Disco su cui vivono firma e PDF: privato, mai servito direttamente.
     */
    public const DOCUMENTS_DISK = 'local';

    protected $fillable = [
        'user_id',
        'issuer_key',
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
        'document_viewed_at',
        'accepted_at',
        'accepted_by',
        'signature_path',
        'signer_name',
        'signer_role',
        'signature_ip',
        'signature_user_agent',
        'pdf_path',
        'rejected_at',
        'rejection_reason',
        'invoice_id',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'sent_at' => 'datetime',
        'reminders_sent' => 'integer',
        'document_viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    /**
     * L'intestazione con cui esce il documento (default se non scelta).
     */
    public function emittente(): Emittente
    {
        return Emittente::make($this->issuer_key);
    }

    /**
     * Pagina del documento: quello che il cliente legge e firma.
     */
    public function documentUrl(): string
    {
        return route('quote.document', $this);
    }

    public function isSigned(): bool
    {
        return $this->signature_path !== null;
    }

    /**
     * True se il cliente può ancora firmarlo o rifiutarlo.
     */
    public function awaitsDecision(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /**
     * Data entro cui l'offerta resta valida (data emissione + validità configurata).
     */
    public function validUntil(): ?Carbon
    {
        $days = (int) config('azienda.validita_giorni');

        return $days > 0 && $this->issue_date ? $this->issue_date->copy()->addDays($days) : null;
    }

    /**
     * La firma come data URI, l'unico modo per inciderla nel PDF senza
     * esporre il file su disco.
     */
    public function signatureDataUri(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }

        $disk = Storage::disk(self::DOCUMENTS_DISK);

        if (! $disk->exists($this->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($disk->get($this->signature_path));
    }

    /**
     * Nome del file proposto al download (il numero può contenere "/").
     */
    public function pdfFileName(): string
    {
        return 'preventivo-'.Str::slug($this->number).'.pdf';
    }
}
