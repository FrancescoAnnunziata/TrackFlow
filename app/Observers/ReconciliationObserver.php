<?php

namespace App\Observers;

use App\Models\Reconciliation;
use App\Services\Reconciliation\ReconciliationService;

/**
 * Mantiene coerenti gli stati derivati (flag `reconciled` del movimento e stato
 * di pagamento del documento) qualunque sia la via con cui una riconciliazione
 * viene creata o rimossa (servizio, relation manager, cascade delete, ...).
 */
class ReconciliationObserver
{
    public function __construct(private readonly ReconciliationService $service) {}

    public function created(Reconciliation $reconciliation): void
    {
        $this->recompute($reconciliation);
    }

    public function deleted(Reconciliation $reconciliation): void
    {
        $this->recompute($reconciliation);
    }

    private function recompute(Reconciliation $reconciliation): void
    {
        $transaction = $reconciliation->bankTransaction;
        if ($transaction !== null) {
            $this->service->recomputeTransaction($transaction->refresh());
        }

        $document = $reconciliation->reconcilable;
        if ($document !== null) {
            $this->service->recomputeDocument($document);
        }
    }
}
