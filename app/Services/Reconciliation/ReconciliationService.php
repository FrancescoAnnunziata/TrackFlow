<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Crea/rimuove le riconciliazioni e mantiene coerenti gli stati derivati:
 * il flag `reconciled` sul movimento e lo stato di pagamento del documento.
 */
class ReconciliationService
{
    /** Tolleranza per considerare un documento coperto (arrotondamenti). */
    private const EPSILON = 0.01;

    /**
     * Collega un movimento a un documento per una certa quota.
     */
    public function attach(BankTransaction $transaction, Model $document, float $amount, string $matchedBy = Reconciliation::BY_MANUAL, int $confidence = 0): Reconciliation
    {
        return DB::transaction(function () use ($transaction, $document, $amount, $matchedBy, $confidence): Reconciliation {
            $reconciliation = $transaction->reconciliations()->create([
                'reconcilable_type' => $document->getMorphClass(),
                'reconcilable_id' => $document->getKey(),
                'amount' => round($amount, 2),
                'matched_by' => $matchedBy,
                'confidence' => $confidence,
            ]);

            $this->recomputeTransaction($transaction->refresh());
            $this->recomputeDocument($document);

            return $reconciliation;
        });
    }

    /**
     * Rimuove una riconciliazione e ricalcola gli stati.
     */
    public function detach(Reconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            $transaction = $reconciliation->bankTransaction;
            $document = $reconciliation->reconcilable;

            $reconciliation->delete();

            if ($transaction !== null) {
                $this->recomputeTransaction($transaction->refresh());
            }
            if ($document !== null) {
                $this->recomputeDocument($document);
            }
        });
    }

    /**
     * Aggiorna il flag `reconciled` del movimento: vero se ha almeno una
     * riconciliazione.
     */
    public function recomputeTransaction(BankTransaction $transaction): void
    {
        $transaction->reconciled = $transaction->reconciliations()->exists();
        $transaction->saveQuietly();
    }

    /**
     * Aggiorna lo stato di pagamento del documento in base alla quota
     * riconciliata rispetto al totale.
     */
    public function recomputeDocument(Model $document): void
    {
        if (! method_exists($document, 'total') || ! method_exists($document, 'reconciledAmount')) {
            return;
        }

        // Tolleranza di copertura: oltre agli arrotondamenti, ammette un piccolo
        // scarto (max €1 o 2% del totale) per gli addebiti in valuta estera, dove
        // il cambio fa pagare qualche centesimo in meno del documento.
        $tolerance = max(self::EPSILON, min(1.0, $document->total() * 0.02));
        $covered = $document->reconciledAmount() + $tolerance >= $document->total();

        if ($document instanceof Invoice) {
            if ($covered) {
                $document->status = 'paid';
            } elseif ($document->status === 'paid') {
                $document->status = 'sent';
            }
            $document->saveQuietly();

            return;
        }

        if ($document instanceof PassiveInvoice) {
            $document->payment_status = $covered
                ? PassiveInvoice::STATUS_PAID
                : PassiveInvoice::STATUS_NOT_PAID;
            $document->saveQuietly();
        }
    }
}
