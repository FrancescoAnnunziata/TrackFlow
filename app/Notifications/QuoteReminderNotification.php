<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteReminderNotification extends Notification
{
    public function __construct(public Quote $quote, public int $daysWaiting)
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
        $issuer = $this->quote->user;
        $url = $this->quote->magicLinkFor($notifiable);

        $mail = (new MailMessage)
            ->subject("Promemoria: preventivo {$this->quote->number} in attesa di risposta")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Il preventivo {$this->quote->number} inviato da {$issuer->name} è in attesa di approvazione da {$this->daysWaiting} giorni.");

        if (filled($this->quote->description)) {
            $mail->line($this->quote->description);
        }

        return $mail
            ->line('Totale (IVA inclusa): € ' . number_format($this->quote->total(), 2, ',', '.'))
            ->action('Approva o rifiuta il preventivo', $url)
            // In copia chi ha emesso il preventivo.
            ->cc($issuer->email);
    }
}
