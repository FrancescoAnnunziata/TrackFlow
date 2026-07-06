<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Aggancia automaticamente i movimenti non riconciliati al miglior candidato,
 * ma solo quando la confidenza è alta (importo esatto + data/nome coerenti).
 * Tutto il resto resta alla riconciliazione manuale.
 */
class AutoReconcile extends Command
{
    protected $signature = 'finance:auto-reconcile
        {--min-confidence=90 : Confidenza minima (0-100) per agganciare in automatico}';

    protected $description = 'Riconcilia in automatico i movimenti bancari con match ad alta confidenza.';

    public function handle(MatchSuggestionService $suggestions, ReconciliationService $reconciler): int
    {
        $minConfidence = (int) $this->option('min-confidence');
        $linked = 0;

        BankTransaction::query()
            ->unreconciled()
            ->orderBy('booked_at')
            ->chunkById(200, function ($transactions) use ($suggestions, $reconciler, $minConfidence, &$linked): void {
                foreach ($transactions as $transaction) {
                    $best = $suggestions->suggestions($transaction, 1)->first();

                    if ($best === null || $best['confidence'] < $minConfidence) {
                        continue;
                    }

                    // In automatico agganciamo solo importi che combaciano esattamente.
                    if (abs($best['amount'] - $transaction->unreconciledAmount()) > 0.01) {
                        continue;
                    }

                    $reconciler->attach(
                        $transaction,
                        $best['model'],
                        $transaction->unreconciledAmount(),
                        matchedBy: \App\Models\Reconciliation::BY_AUTO,
                        confidence: $best['confidence'],
                    );
                    $linked++;
                }
            });

        $this->info("Movimenti riconciliati in automatico: {$linked}.");

        return self::SUCCESS;
    }
}
