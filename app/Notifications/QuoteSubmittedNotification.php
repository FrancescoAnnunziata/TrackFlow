<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuoteSubmittedNotification extends Notification
{
    public function __construct(public Quote $quote)
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
            ->subject("Preventivo {$this->quote->number} da approvare")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$issuer->name} ti ha inviato il preventivo {$this->quote->number} da approvare.");

        if (filled($this->quote->description)) {
            $mail->line($this->quote->description);
        }

        return $mail
            ->line('Ore stimate: ' . self::formatHours($this->quote->estimated_hours)
                . ' — Totale (IVA inclusa): € ' . number_format($this->quote->total(), 2, ',', '.'))
            ->action('Visualizza e approva', $url)
            ->line('Il link di accesso è valido ' . Quote::MAGIC_LINK_DAYS . ' giorni e ti permette di entrare senza password.')
            // In copia chi ha emesso il preventivo.
            ->cc($issuer->email);
    }

    private static function formatHours(string|float $hours): string
    {
        return rtrim(rtrim(number_format((float) $hours, 1, ',', '.'), '0'), ',') . ' h';
    }
}
