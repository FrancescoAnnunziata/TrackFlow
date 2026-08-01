<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\PassiveInvoice;
use Illuminate\Support\Str;

/**
 * Azioni di chiusura di un movimento bancario che non sono riconciliazioni verso
 * un documento esistente: creare un costo dal movimento, o marcarlo come
 * giroconto. Logica condivisa fra le azioni della tabella e l'assistente AI.
 */
class MovementActions
{
    public function __construct(private readonly ReconciliationService $reconciler) {}

    /** Categorie (conti) suggerite, dai costi/fatture passive già registrati. */
    public function categories(): array
    {
        return Costo::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')
            ->merge(PassiveInvoice::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category'))
            ->unique()->sort()->values()->all();
    }

    /**
     * Crea un Costo dai dati del movimento e lo riconcilia. Ritorna il costo.
     */
    public function markAsCost(BankTransaction $tx, string $description, ?string $category, ?int $supplierId, ?float $amount = null): Costo
    {
        $amount = $amount ?? $tx->unreconciledAmount();

        $costo = Costo::create([
            'date' => $tx->booked_at,
            'description' => Str::limit($description ?: 'Costo', 120),
            'category' => $category ?: null,
            'supplier_id' => $supplierId ?: null,
            'amount' => (float) $amount,
            'vat_amount' => 0,
            'bank_transaction_id' => $tx->id,
        ]);

        $this->reconciler->attach($tx, $costo, (float) $amount);

        return $costo;
    }

    /**
     * Collega più movimenti in un'unica partita di giro: giroconto 1↔1 (2
     * movimenti) o uno-a-molti (es. un rimborso a fronte di più uscite). Tutti i
     * membri condividono lo stesso transfer_group_id (l'id più piccolo, come
     * àncora). Servono almeno due movimenti.
     *
     * @param  iterable<BankTransaction>  $movements
     */
    public function markAsTransferGroup(iterable $movements): void
    {
        $members = collect($movements)->filter()->unique(fn (BankTransaction $m): int => $m->id)->values();
        if ($members->count() < 2) {
            return;
        }

        $groupId = (int) $members->min(fn (BankTransaction $m): int => $m->id);
        foreach ($members as $m) {
            $m->update(['transfer_group_id' => $groupId]);
        }
    }

    /**
     * Marca due movimenti come giroconto reciproco (spostamento tra conti).
     */
    public function markAsTransfer(BankTransaction $tx, BankTransaction $pair): void
    {
        $this->markAsTransferGroup([$tx, $pair]);
    }

    /**
     * Scioglie l'intera partita di giro a cui appartiene il movimento.
     */
    public function clearTransferGroup(BankTransaction $tx): void
    {
        if ($tx->transfer_group_id === null) {
            return;
        }

        BankTransaction::query()
            ->where('transfer_group_id', $tx->transfer_group_id)
            ->update(['transfer_group_id' => null]);
    }
}
