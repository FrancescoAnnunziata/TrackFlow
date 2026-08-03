<?php

namespace App\Notifications;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuotePdf;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class QuoteDecidedNotification extends Notification
{
    public function __construct(public Quote $quote, public User $decidedBy) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $accepted = $this->quote->status === Quote::STATUS_ACCEPTED;
        $issuer = $this->quote->user;
        $isIssuer = $notifiable->getKey() === $issuer->getKey();

        $mail = (new MailMessage)
            ->subject($accepted
                ? "Preventivo {$this->quote->number} firmato e accettato"
                : "Preventivo {$this->quote->number} rifiutato")
            ->greeting("Ciao {$notifiable->name},");

        if ($accepted) {
            $mail->line($isIssuer
                ? "{$this->decidedBy->name} ha firmato e accettato il preventivo {$this->quote->number}."
                : "Hai firmato e accettato il preventivo {$this->quote->number}: in allegato la copia in PDF con la tua firma.");

            if ($this->quote->signer_name) {
                $mail->line('Firmato da '.$this->quote->signer_name
                    .($this->quote->signer_role ? " ({$this->quote->signer_role})" : '')
                    .' il '.$this->quote->accepted_at?->format('d/m/Y \a\l\l\e H:i').'.');
            }
        } else {
            $mail->line($isIssuer
                ? "{$this->decidedBy->name} ha rifiutato il preventivo {$this->quote->number}."
                : "Hai rifiutato il preventivo {$this->quote->number}.");

            if (filled($this->quote->rejection_reason)) {
                $mail->line('Motivo indicato: '.$this->quote->rejection_reason);
            }
        }

        $mail->line('Totale (IVA inclusa): € '.number_format($this->quote->total(), 2, ',', '.'));

        // L'admin è già autenticato: link al pannello. Al cliente serve il magic
        // link, che non va mai mandato a nessun altro.
        $mail->action(
            $accepted ? 'Apri il documento firmato' : 'Apri il preventivo',
            $isIssuer
                ? QuoteResource::getUrl('view', ['record' => $this->quote])
                : $this->quote->magicLinkFor($notifiable),
        );

        // Il PDF firmato viaggia in allegato: entrambe le parti ne hanno una
        // copia anche senza rientrare nell'applicazione.
        if ($accepted && ($pdf = $this->signedPdf()) !== null) {
            $mail->attachData($pdf, $this->quote->pdfFileName(), ['mime' => 'application/pdf']);
        }

        return $mail;
    }

    /**
     * I byte della copia congelata alla firma (rigenerata se il file manca).
     */
    private function signedPdf(): ?string
    {
        $path = QuotePdf::ensureStored($this->quote);

        return Storage::disk(Quote::DOCUMENTS_DISK)->get($path);
    }
}
