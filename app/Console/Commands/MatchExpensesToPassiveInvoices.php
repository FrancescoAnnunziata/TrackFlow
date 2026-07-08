<?php

namespace App\Console\Commands;

use App\Services\Billing\ExpensePassiveMatcher;
use Illuminate\Console\Command;

/**
 * Collega automaticamente le spese alle fatture passive importate da Fatture in
 * Cloud quando c'è un match esatto e non ambiguo (stessa data, stesso importo
 * lordo). La spesa eredita fornitore e conto dalla fattura passiva. Il resto
 * resta all'abbinamento manuale.
 */
class MatchExpensesToPassiveInvoices extends Command
{
    protected $signature = 'expenses:match-passive';

    protected $description = 'Abbina in automatico le spese alle fatture passive con match esatto (data + importo).';

    public function handle(ExpensePassiveMatcher $matcher): int
    {
        $linked = $matcher->autoLinkExact();

        $this->info("Spese collegate a una fattura passiva: {$linked}.");

        return self::SUCCESS;
    }
}
