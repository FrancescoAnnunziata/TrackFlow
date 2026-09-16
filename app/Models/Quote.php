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
     * Come è arrivata l'accettazione. La firma online è l'unica che il cliente
     * appone da sé: le altre tre le registra l'admin da pannello, ed è per
     * questo che si portano dietro autore, prova allegata e chi le ha inserite.
     */
    public const METHOD_SIGNATURE = 'signature';

    public const METHOD_EMAIL = 'email';

    public const METHOD_VERBAL = 'verbal';

    public const METHOD_PAPER = 'paper';

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
        'acceptance_method',
        'acceptance_author',
        'acceptance_author_role',
        'acceptance_recorded_by',
        'acceptance_recorded_at',
        'acceptance_evidence_path',
        'acceptance_note',
        'signed_copy_path',
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
        'acceptance_recorded_at' => 'datetime',
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
     * L'admin che ha registrato a mano un'accettazione arrivata fuori da
     * TrackFlow (email, telefonata, foglio firmato).
     */
    public function acceptanceRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acceptance_recorded_by');
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
     * Link da mettere nelle email al referente: porta sul documento e la sua
     * firma vale come autenticazione, così il cliente non deve fare login né
     * avere una password (magic link, vedi QuoteMagicAccess).
     */
    public function magicLinkFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'quote.document',
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
     * Come è arrivata l'accettazione. I preventivi firmati prima che esistesse
     * il campo non ce l'hanno scritto: per loro vale la firma online.
     */
    public function acceptanceMethod(): ?string
    {
        return $this->acceptance_method ?? ($this->isSigned() ? self::METHOD_SIGNATURE : null);
    }

    /**
     * @return array<string, string>
     */
    public static function acceptanceMethodOptions(): array
    {
        return [
            self::METHOD_EMAIL => 'Per email',
            self::METHOD_VERBAL => 'A voce (telefono o di persona)',
            self::METHOD_PAPER => 'Su carta, documento firmato',
        ];
    }

    public function acceptanceMethodLabel(): ?string
    {
        return match ($this->acceptanceMethod()) {
            self::METHOD_SIGNATURE => 'Firma online sul documento',
            self::METHOD_EMAIL => 'Per email',
            self::METHOD_VERBAL => 'A voce (telefono o di persona)',
            self::METHOD_PAPER => 'Su carta, documento firmato',
            default => null,
        };
    }

    /**
     * True se l'accettazione l'ha registrata un admin invece di arrivare dalla
     * firma online del cliente.
     */
    public function acceptanceWasRecorded(): bool
    {
        $method = $this->acceptanceMethod();

        return $method !== null && $method !== self::METHOD_SIGNATURE;
    }

    public function hasSignedCopy(): bool
    {
        return $this->signed_copy_path !== null;
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_INVOICED], true);
    }

    /**
     * Accettato ma senza niente di firmato agli atti: né la firma online né la
     * copia cartacea. È il caso del «procedi intanto, formalizziamo dopo», che
     * senza un promemoria si dimentica.
     */
    public function needsFormalization(): bool
    {
        return $this->isAccepted() && ! $this->isSigned() && ! $this->hasSignedCopy();
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

    /**
     * Nome della scansione firmata proposta al download.
     */
    public function signedCopyFileName(): string
    {
        return 'preventivo-'.Str::slug($this->number).'-firmato.pdf';
    }
}
