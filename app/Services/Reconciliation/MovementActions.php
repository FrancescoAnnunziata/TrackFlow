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
     * Marca due movimenti come giroconto reciproco (spostamento tra conti).
     */
    public function markAsTransfer(BankTransaction $tx, BankTransaction $pair): void
    {
        $tx->update(['transfer_pair_id' => $pair->id]);
        $pair->update(['transfer_pair_id' => $tx->id]);
    }
}
