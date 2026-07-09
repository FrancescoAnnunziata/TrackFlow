<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use Illuminate\Console\Command;

/**
 * Trova e marca i giroconti tra conti propri: un'uscita con un'entrata gemella
 * di pari importo su un ALTRO conto, entro pochi giorni. Collega i due movimenti
 * (transfer_pair_id reciproco) così sono riconoscibili in prima nota e la
 * dashboard può escluderli dal quadro operativo. Idempotente: salta i movimenti
 * già marcati. Eseguibile anche in produzione.
 */
class DetectTransfers extends Command
{
    protected $signature = 'finance:detect-transfers {--window=7 : Giorni entro cui accettare la gemella} {--dry-run : Mostra soltanto cosa farebbe}';

    protected $description = 'Rileva e marca i giroconti tra conti (uscita + entrata gemella su altro conto).';

    public function handle(): int
    {
        $window = (int) $this->option('window');
        $dry = (bool) $this->option('dry-run');

        // Solo movimenti non ancora marcati: idempotenza.
        $out = BankTransaction::whereNull('transfer_pair_id')->where('amount', '<', 0)
            ->orderBy('booked_at')->orderBy('id')->get();
        $in = BankTransaction::whereNull('transfer_pair_id')->where('amount', '>', 0)
            ->orderBy('booked_at')->orderBy('id')->get();

        $used = [];
        $paired = 0;

        foreach ($out as $o) {
            $amt = abs((float) $o->amount);

            $match = $in->first(fn (BankTransaction $i): bool => ! isset($used[$i->id])
                && $i->bank_account_id !== $o->bank_account_id
                && abs((float) $i->amount - $amt) < 0.01
                && abs($i->booked_at->diffInDays($o->booked_at)) <= $window);

            if ($match === null) {
                continue;
            }

            $used[$match->id] = true;
            $paired++;

            $this->line(sprintf(
                '  giroconto: %s €%s  uscita conto#%d ↔ entrata conto#%d',
                $o->booked_at->format('d/m/Y'), number_format($amt, 2, ',', '.'),
                $o->bank_account_id, $match->bank_account_id,
            ));

            if (! $dry) {
                $o->update(['transfer_pair_id' => $match->id]);
                $match->update(['transfer_pair_id' => $o->id]);
            }
        }

        $this->info(($dry ? '[dry-run] ' : '').'Giroconti '.($dry ? 'trovati' : 'marcati').": {$paired}");

        return self::SUCCESS;
    }
}
