<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\LogHoursReminderNotification;
use Illuminate\Console\Command;

class SendHoursReminders extends Command
{
    protected $signature = 'hours:send-reminders {--dry-run : Mostra i destinatari senza inviare nulla}';

    protected $description = 'Invia il promemoria giornaliero "segna le ore" agli utenti admin/member che lo hanno attivato e non hanno ancora registrato ore oggi.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $recipients = User::query()
            ->whereIn('role', ['admin', 'member'])
            ->where('hours_reminder_opt_in', true)
            // Chi ha gia' segnato ore oggi non ha bisogno del promemoria.
            ->whereDoesntHave('hours', fn ($query) => $query->whereDate('date', today()))
            ->get();

        if ($recipients->isEmpty()) {
            $this->info('Nessun destinatario: nessun promemoria da inviare.');

            return self::SUCCESS;
        }

        foreach ($recipients as $user) {
            if ($dryRun) {
                $this->line("[dry-run] {$user->email}");

                continue;
            }

            $user->notify(new LogHoursReminderNotification());
        }

        $verb = $dryRun ? 'Da inviare' : 'Inviati';
        $this->info("{$verb}: {$recipients->count()} promemoria.");

        return self::SUCCESS;
    }
}
