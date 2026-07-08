<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Aggancia automaticamente i movimenti non riconciliati, con due criteri:
 *  1) importo esatto + candidato UNICO → aggancia a prescindere dalla data
 *     (l'unicità dell'importo è già un segnale forte, la data non conta);
 *  2) altrimenti il miglior candidato, ma solo se supera la soglia di confidenza
 *     (importo esatto + data/nome coerenti).
 * Tutto il resto resta alla riconciliazione manuale.
 */
class AutoReconcile extends Command
{
    protected $signature = 'finance:auto-reconcile
        {--min-confidence=90 : Confidenza minima (0-100) per il match per confidenza (criterio 2)}
        {--fuzzy : Passa anche i match a importo ravvicinato + nome fornitore (valuta estera, es. AWS)}';

    protected $description = 'Riconcilia in automatico i movimenti bancari con match ad alta confidenza.';

    public function handle(MatchSuggestionService $suggestions, ReconciliationService $reconciler): int
    {
        $minConfidence = (int) $this->option('min-confidence');
        $byUnique = 0;
        $byConfidence = 0;

        BankTransaction::query()
            ->unreconciled()
            ->orderBy('booked_at')
            ->chunkById(200, function ($transactions) use ($suggestions, $reconciler, $minConfidence, &$byUnique, &$byConfidence): void {
                foreach ($transactions as $transaction) {
                    $target = (float) $transaction->unreconciledAmount();
                    $candidates = $suggestions->suggestions($transaction, 50);

                    // Solo i candidati con importo esattamente uguale.
                    $exact = $candidates->filter(fn (array $c): bool => abs($c['amount'] - $target) <= 0.01)->values();

                    // Criterio 1: un solo documento con quell'importo → aggancia.
                    if ($exact->count() === 1) {
                        $reconciler->attach(
                            $transaction, $exact->first()['model'], $target,
                            matchedBy: \App\Models\Reconciliation::BY_AUTO,
                            confidence: $exact->first()['confidence'],
                        );
                        $byUnique++;

                        continue;
                    }

                    // Criterio 2: importo esatto ambiguo → il migliore se supera la soglia.
                    $best = $candidates->first();
                    if ($best !== null
                        && $best['confidence'] >= $minConfidence
                        && abs($best['amount'] - $target) <= 0.01) {
                        $reconciler->attach(
                            $transaction, $best['model'], $target,
                            matchedBy: \App\Models\Reconciliation::BY_AUTO,
                            confidence: $best['confidence'],
                        );
                        $byConfidence++;
                    }
                }
            });

        $byFuzzy = $this->option('fuzzy') ? $this->fuzzyPass($suggestions, $reconciler) : 0;

        $this->info(sprintf(
            'Movimenti riconciliati: %d (%d per importo unico, %d per confidenza%s).',
            $byUnique + $byConfidence + $byFuzzy, $byUnique, $byConfidence,
            $byFuzzy > 0 ? ", {$byFuzzy} fuzzy" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Pass fuzzy: raccoglie tutte le coppie (uscita → miglior fattura passiva per
     * nome+importo ravvicinato+data), poi assegna in modo greedy dalle più strette
     * alle più larghe, senza riusare né movimento né fattura. Così l'uscita da
     * 11,56 va alla passiva da 11,53 (diff 0,03) e non a quella da 12,31.
     */
    private function fuzzyPass(MatchSuggestionService $suggestions, ReconciliationService $reconciler): int
    {
        $pairs = [];

        BankTransaction::query()->unreconciled()->where('amount', '<', 0)
            ->orderBy('booked_at')
            ->chunkById(200, function ($transactions) use ($suggestions, &$pairs): void {
                foreach ($transactions as $transaction) {
                    $best = $suggestions->fuzzyPassiveMatches($transaction)->first();
                    if ($best !== null) {
                        $pairs[] = [
                            'tx' => $transaction,
                            'passive' => $best['model'],
                            'diff' => $best['diff'],
                        ];
                    }
                }
            });

        usort($pairs, fn (array $a, array $b): int => $a['diff'] <=> $b['diff']);

        $usedTx = [];
        $usedPassive = [];
        $linked = 0;

        foreach ($pairs as $pair) {
            $txId = $pair['tx']->getKey();
            $passiveId = $pair['passive']->getKey();

            if (isset($usedTx[$txId]) || isset($usedPassive[$passiveId])) {
                continue;
            }

            $reconciler->attach(
                $pair['tx'], $pair['passive'], (float) $pair['tx']->unreconciledAmount(),
                matchedBy: \App\Models\Reconciliation::BY_AUTO,
                confidence: 0,
            );

            $usedTx[$txId] = true;
            $usedPassive[$passiveId] = true;
            $linked++;
        }

        return $linked;
    }
}
