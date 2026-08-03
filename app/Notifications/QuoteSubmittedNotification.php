<?php

namespace App\Notifications;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteSubmittedNotification extends Notification
{
    public function __construct(public Quote $quote) {}

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
        // L'emittente riceve una copia per conoscenza, ma con link al pannello
        // (NON il magic link, che autenticherebbe chi clicca COME il cliente).
        if ($notifiable->getKey() === $this->quote->user_id) {
            return $this->issuerCopy($notifiable);
        }

        $mail = (new MailMessage)
            ->subject("Preventivo {$this->quote->number} da firmare")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$this->quote->user->name} ti ha inviato il preventivo {$this->quote->number}. Puoi leggerlo per intero e accettarlo firmandolo direttamente online.");

        if (filled($this->quote->description)) {
            $mail->line($this->quote->description);
        }

        return $mail
            ->line('Ore stimate: '.self::formatHours($this->quote->estimated_hours)
                .' — Totale (IVA inclusa): € '.number_format($this->quote->total(), 2, ',', '.'))
            ->action('Leggi e firma il preventivo', $this->quote->magicLinkFor($notifiable))
            ->line('Ti basta disegnare la tua firma nel riquadro in fondo al documento: riceverai subito la copia in PDF.')
            ->line('Il link di accesso è valido '.Quote::MAGIC_LINK_DAYS.' giorni e ti permette di entrare senza password.');
    }

    private function issuerCopy(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Preventivo {$this->quote->number} inviato al cliente")
            ->greeting("Ciao {$notifiable->name},")
            ->line("Hai inviato il preventivo {$this->quote->number} ai referenti di {$this->quote->client->name}.")
            ->line('Totale (IVA inclusa): € '.number_format($this->quote->total(), 2, ',', '.'))
            ->action('Apri il preventivo', QuoteResource::getUrl('view', ['record' => $this->quote]));
    }

    private static function formatHours(string|float $hours): string
    {
        return rtrim(rtrim(number_format((float) $hours, 1, ',', '.'), '0'), ',').' h';
    }
}
