<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\GroupedMatchService;
use Illuminate\Console\Command;

/**
 * Riconcilia i pagamenti non 1:1 fra movimenti e fatture passive: una fattura
 * pagata da più movimenti (spezzati) e un movimento che paga più fatture
 * (cumulativi, es. addebito Telepass). Solo combinazioni uniche e con il nome
 * del fornitore nella descrizione del movimento.
 */
class MatchGroupedPayments extends Command
{
    protected $signature = 'finance:match-grouped';

    protected $description = 'Riconcilia pagamenti spezzati (1 fattura = più movimenti) e cumulativi (1 movimento = più fatture).';

    public function handle(GroupedMatchService $service): int
    {
        $result = $service->run();

        $this->info(sprintf(
            'Riconciliati: %d spezzati (fattura da più movimenti), %d cumulativi (movimento su più fatture).',
            $result['split'], $result['grouped'],
        ));

        return self::SUCCESS;
    }
}
