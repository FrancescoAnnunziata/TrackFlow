<?php

namespace App\Notifications;

use App\Filament\Resources\Abbonamenti\AbbonamentoResource;
use App\Models\Abbonamento;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Una sola mail con gli abbonamenti che si rinnovano nei prossimi giorni.
 */
class AbbonamentiInRinnovoNotification extends Notification
{
    /** @param  Collection<int, Abbonamento>  $abbonamenti */
    public function __construct(public Collection $abbonamenti) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->abbonamenti->count();
        $subject = $count === 1
            ? 'Rinnovo in arrivo: '.$this->abbonamenti->first()->name.' il '.$this->abbonamenti->first()->next_renewal_on->format('d/m')
            : "Rinnovi in arrivo: {$count} abbonamenti nei prossimi giorni";

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($count === 1
                ? 'Questo abbonamento si rinnova fra pochi giorni. Se non ti serve più, è il momento di disdirlo.'
                : 'Questi abbonamenti si rinnovano fra pochi giorni. Se qualcuno non ti serve più, è il momento di disdirlo.');

        foreach ($this->abbonamenti as $a) {
            $line = sprintf(
                '**%s** — %s, rinnovo %s il %s',
                $a->name,
                $a->amount !== null ? '€ '.number_format((float) $a->amount, 2, ',', '.') : 'importo non indicato',
                mb_strtolower($a->cadenceLabel()),
                $a->next_renewal_on->format('d/m/Y'),
            );
            if (filled($a->notes)) {
                $line .= ' · '.$a->notes;
            }
            $mail->line($line);
        }

        return $mail->action('Apri gli abbonamenti', AbbonamentoResource::getUrl('index', panel: 'app'));
    }
}
