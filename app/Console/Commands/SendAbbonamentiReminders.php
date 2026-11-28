<?php

namespace App\Console\Commands;

use App\Models\Abbonamento;
use App\Notifications\AbbonamentiInRinnovoNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendAbbonamentiReminders extends Command
{
    protected $signature = 'abbonamenti:promemoria {--dry-run : Mostra cosa verrebbe inviato senza mandare nulla}';

    protected $description = 'Manda una mail una settimana prima del rinnovo degli abbonamenti fornitori, e sposta al giro dopo i rinnovi già passati.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = CarbonImmutable::today();
        $limit = $today->addDays(Abbonamento::REMINDER_DAYS);

        // Prima i rinnovi passati senza un addebito segnato: vanno al giro dopo,
        // sennò il promemoria dell'anno successivo non partirebbe mai.
        foreach (Abbonamento::whereDate('next_renewal_on', '<', $today)->get() as $abbonamento) {
            if ($abbonamento->rollForward($today) && ! $dryRun) {
                $abbonamento->save();
            }
        }

        $due = Abbonamento::query()
            ->with('supplier')
            ->whereDate('next_renewal_on', '>=', $today)
            ->whereDate('next_renewal_on', '<=', $limit)
            ->orderBy('next_renewal_on')
            ->get()
            // Una mail sola per rinnovo, non una ogni mattina della settimana.
            ->reject(fn (Abbonamento $a): bool => $a->reminded_for !== null && $a->reminded_for->equalTo($a->next_renewal_on))
            ->values();

        if ($due->isEmpty()) {
            $this->info('Nessun rinnovo nei prossimi '.Abbonamento::REMINDER_DAYS.' giorni da segnalare.');

            return self::SUCCESS;
        }

        $to = (string) config('services.abbonamenti.promemoria_a');

        foreach ($due as $a) {
            $this->line(sprintf('%s → %s', $a->next_renewal_on->format('d/m/Y'), $a->name));
        }

        if ($dryRun) {
            $this->info("[dry-run] {$due->count()} rinnovi, mail non inviata a {$to}.");

            return self::SUCCESS;
        }

        Notification::route('mail', $to)->notify(new AbbonamentiInRinnovoNotification($due));

        foreach ($due as $a) {
            $a->reminded_for = $a->next_renewal_on;
            $a->save();
        }

        $this->info("Mail inviata a {$to} per {$due->count()} rinnovi.");

        return self::SUCCESS;
    }
}
