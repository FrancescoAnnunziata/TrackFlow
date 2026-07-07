<?php

namespace App\Observers;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\Expense;
use App\Models\Reimbursement;

class ExpenseObserver
{
    /**
     * Mantiene allineato il rimborso "carta personale" con la spesa:
     * - spesa con carta personale  -> crea/aggiorna il rimborso collegato;
     * - spesa con carta aziendale  -> rimuove l'eventuale rimborso collegato.
     */
    public function saved(Expense $expense): void
    {
        if (! $expense->paid_with_personal_card) {
            $expense->reimbursement()?->delete();

            return;
        }

        $reimbursement = Reimbursement::firstOrNew(['expense_id' => $expense->id]);

        // Lo stato (approvazione/rimborso) e' gestito a mano dall'admin: lo si
        // imposta solo alla creazione, senza sovrascriverlo agli aggiornamenti.
        if (! $reimbursement->exists) {
            $reimbursement->status = ReimbursementStatus::Pending;
        }

        $reimbursement->fill([
            'user_id' => $expense->user_id,
            'client_id' => $expense->client_id,
            'type' => ReimbursementType::PersonalCard,
            'date' => $expense->date,
            'amount' => $expense->amount,
            'notes' => $expense->notes,
            'attachments' => $expense->attachaments,
        ])->save();
    }

    public function deleted(Expense $expense): void
    {
        $expense->reimbursement()?->delete();
    }
}
