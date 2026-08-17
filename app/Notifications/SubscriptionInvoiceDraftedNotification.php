<?php

namespace App\Notifications;

use App\Http\Controllers\Api\SubscriptionInvoiceController;
use App\Models\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un abbonamento è stato incassato e la fattura è pronta in bozza: l'invio a
 * Fatture in Cloud lo fai tu dal pannello, quindi qualcuno deve saperlo.
 */
class SubscriptionInvoiceDraftedNotification extends Notification
{
    public function __construct(public Invoice $invoice) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->invoice->client?->name ?? 'cliente sconosciuto';
        $total = number_format($this->invoice->total(), 2, ',', '.');

        $mail = (new MailMessage)
            ->subject("Abbonamento incassato: fattura da emettere per {$client}")
            ->line("È arrivato un incasso da abbonamento e la fattura per **{$client}** è pronta in bozza su TrackFlow.")
            ->line("Totale: € {$total} (IVA inclusa) — periodo "
                .$this->invoice->period_from?->format('d/m/Y').' - '
                .$this->invoice->period_to?->format('d/m/Y').'.')
            ->line('Il numero lo assegnerà Fatture in Cloud al momento dell\'invio.');

        $url = SubscriptionInvoiceController::panelUrl($this->invoice);

        if ($url !== null) {
            $mail->action('Apri la fattura', $url);
        }

        return $mail->line('Controlla i dati fiscali del cliente e poi inviala a Fatture in Cloud dal pannello.');
    }
}
