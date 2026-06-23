<?php

namespace App\Console\Commands;

use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendQuoteReminders extends Command
{
    protected $signature = 'quotes:send-reminders {--dry-run : Mostra cosa verrebbe inviato senza inviare nulla}';

    protected $description = 'Invia solleciti per i preventivi inviati e non ancora accettati/rifiutati (a 5 e 10 giorni).';

    /**
     * Soglie di sollecito in giorni, abbinate al contatore reminders_sent:
     * dopo 5 giorni il 1° sollecito, dopo 10 il 2°.
     */
    private const MILESTONES = [
        ['days' => 10, 'after' => 1], // 2° sollecito: invia se ne è già partito 1
        ['days' => 5, 'after' => 0],  // 1° sollecito: invia se non ne è partito nessuno
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $quotes = Quote::query()
            ->where('status', Quote::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($quotes as $quote) {
            $days = (int) $quote->sent_at->startOfDay()->diffInDays(now()->startOfDay());

            // Scegli il sollecito più avanzato dovuto (gestisce anche giorni saltati).
            $due = null;
            foreach (self::MILESTONES as $milestone) {
                if ($days >= $milestone['days'] && $quote->reminders_sent === $milestone['after']) {
                    $due = $milestone;
                    break;
                }
            }

            if ($due === null) {
                continue;
            }

            $recipients = User::query()
                ->where('role', 'client')
                ->where('client_id', $quote->client_id)
                ->get();

            if ($recipients->isEmpty()) {
                $this->warn("Preventivo {$quote->number}: nessun referente, salto.");

                continue;
            }

            $this->line(sprintf(
                '%s Preventivo %s (%d gg, sollecito #%d) → %d referente/i',
                $dryRun ? '[dry-run]' : '✉',
                $quote->number,
                $days,
                $due['after'] + 1,
                $recipients->count(),
            ));

            if (! $dryRun) {
                Notification::send($recipients, new QuoteReminderNotification($quote, $days));
                $quote->update(['reminders_sent' => $due['after'] + 1]);
            }

            $sent++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Solleciti elaborati: {$sent}.");

        return self::SUCCESS;
    }
}
