<?php

namespace App\Notifications;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteDecidedNotification extends Notification
{
    public function __construct(public Quote $quote, public User $decidedBy)
    {
    }

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
        $word = $accepted ? 'accettato' : 'rifiutato';
        $issuer = $this->quote->user;
        $isIssuer = $notifiable->getKey() === $issuer->getKey();

        $mail = (new MailMessage)
            ->subject("Preventivo {$this->quote->number} {$word}")
            ->greeting("Ciao {$notifiable->name},");

        if ($isIssuer) {
            // L'admin è già autenticato: link diretto al pannello, niente magic link.
            $mail
                ->line("Il preventivo {$this->quote->number} è stato {$word} da {$this->decidedBy->name}.")
                ->action('Apri il preventivo', QuoteResource::getUrl('view', ['record' => $this->quote]));
        } else {
            // Solo il cliente riceve il magic link, mai in CC ad altri.
            $mail
                ->line("Hai {$word} il preventivo {$this->quote->number}.")
                ->action('Apri il preventivo', $this->quote->magicLinkFor($notifiable));
        }

        return $mail
            ->line('Totale (IVA inclusa): € ' . number_format($this->quote->total(), 2, ',', '.'));
    }
}
