<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use Illuminate\Database\Eloquent\Model;

/**
 * Riconcilia un movimento bancario a uno o più documenti, allocando a ciascuno
 * il suo residuo finché la quota del movimento non è esaurita (gestisce anche la
 * combinazione di più documenti la cui somma torna). Logica condivisa fra il
 * popup dei Movimenti bancari e l'assistente AI.
 */
class MovementReconciler
{
    public function __construct(private readonly ReconciliationService $service) {}

    /** Tipi di documento accettati (alias morph → classe). */
    private const TYPES = [
        'invoice' => Invoice::class,
        'passive_invoice' => PassiveInvoice::class,
        'costo' => Costo::class,
        'expense' => Expense::class,
        'reimbursement' => Reimbursement::class,
    ];

    public function resolve(string $type, int $id): ?Model
    {
        $class = self::TYPES[$type] ?? null;

        return $class ? $class::find($id) : null;
    }

    /**
     * Residuo ancora scoperto di un documento (bersaglio meno già riconciliato).
     */
    public function documentResidual(Model $document): float
    {
        if (! method_exists($document, 'total')) {
            return 0.0;
        }

        $target = $document instanceof Invoice ? $document->amountToCollect() : $document->total();
        $reconciled = method_exists($document, 'reconciledAmount') ? (float) $document->reconciledAmount() : 0.0;

        return round(max(0, $target - $reconciled), 2);
    }

    /**
     * Esegue la riconciliazione del movimento verso i documenti indicati.
     *
     * @param  array<int, array{type: string, id: int}>  $targets
     * @return array{attached: int, remaining: float, lines: array<int, array{label: string, amount: float}>}
     */
    public function reconcile(BankTransaction $transaction, array $targets): array
    {
        $remaining = round($transaction->unreconciledAmount(), 2);
        $attached = 0;
        $lines = [];

        foreach ($targets as $target) {
            if ($remaining <= 0.01) {
                break;
            }

            $document = $this->resolve((string) ($target['type'] ?? ''), (int) ($target['id'] ?? 0));
            if ($document === null) {
                continue;
            }

            $alloc = round(min($this->documentResidual($document), $remaining), 2);
            if ($alloc <= 0.01) {
                continue;
            }

            $this->service->attach($transaction, $document, $alloc);
            $remaining = round($remaining - $alloc, 2);
            $attached++;
            $lines[] = ['label' => $this->label($document), 'amount' => $alloc];
        }

        return ['attached' => $attached, 'remaining' => $remaining, 'lines' => $lines];
    }

    public function label(Model $document): string
    {
        return match (true) {
            $document instanceof Invoice => 'Fattura '.($document->number ?: '(s.n.)').' — '.($document->client->name ?? '—'),
            $document instanceof PassiveInvoice => 'Fattura passiva '.($document->number ?: '(s.n.)').' — '.($document->supplier->name ?? '—'),
            $document instanceof Costo => 'Costo — '.$document->description,
            $document instanceof Expense => 'Spesa — '.($document->supplier->name ?? optional($document->date)->format('d/m/Y') ?? ''),
            $document instanceof Reimbursement => 'Rimborso spese '.(optional($document->date)->format('d/m/Y') ?? '').' — '.($document->notes ?? ''),
            default => class_basename($document),
        };
    }
}
