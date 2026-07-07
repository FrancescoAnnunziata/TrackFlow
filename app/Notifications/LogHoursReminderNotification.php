<?php

namespace App\Notifications;

use App\Filament\Resources\Hours\HourResource;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LogHoursReminderNotification extends Notification
{
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
        return (new MailMessage)
            ->from(config('mail.from.address'), 'g8labs')
            ->subject('Promemoria: segna le ore di oggi')
            ->greeting("Ciao {$notifiable->name},")
            ->line('Questo è il promemoria giornaliero per registrare le ore lavorate oggi.')
            // Link diretto alla finestra di aggiunta ore.
            ->action('Aggiungi ore', HourResource::getUrl('create'))
            ->line('Oppure apri l\'elenco delle ore: '.HourResource::getUrl('index'))
            ->line('Ricevi questa email perché hai attivato il promemoria. Puoi disattivarlo dalle Preferenze notifiche.');
    }
}
