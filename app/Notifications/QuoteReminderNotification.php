<?php

namespace App\Notifications;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteReminderNotification extends Notification
{
    public function __construct(public Quote $quote, public int $daysWaiting) {}

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
        // L'emittente riceve una copia per conoscenza con link al pannello,
        // non il magic link del cliente.
        if ($notifiable->getKey() === $this->quote->user_id) {
            return (new MailMessage)
                ->subject("Sollecito inviato: preventivo {$this->quote->number} ancora in attesa")
                ->greeting("Ciao {$notifiable->name},")
                ->line("Il preventivo {$this->quote->number} per {$this->quote->client->name} è in attesa di risposta da {$this->daysWaiting} giorni. È stato inviato un sollecito ai referenti.")
                ->action('Apri il preventivo', QuoteResource::getUrl('view', ['record' => $this->quote]));
        }

        $mail = (new MailMessage)
            ->subject("Promemoria: preventivo {$this->quote->number} in attesa di risposta")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Il preventivo {$this->quote->number} inviato da {$this->quote->user->name} è in attesa di approvazione da {$this->daysWaiting} giorni.");

        if (filled($this->quote->description)) {
            $mail->line($this->quote->description);
        }

        return $mail
            ->line('Totale (IVA inclusa): € '.number_format($this->quote->total(), 2, ',', '.'))
            ->action('Leggi e firma il preventivo', $this->quote->magicLinkFor($notifiable));
    }
}
