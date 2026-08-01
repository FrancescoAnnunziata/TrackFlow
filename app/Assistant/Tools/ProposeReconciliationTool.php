<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\BankTransaction;
use App\Services\Reconciliation\MovementReconciler;

/**
 * Prepara una PROPOSTA di riconciliazione fra un movimento e uno o più documenti.
 * NON scrive a DB: la scrittura avviene solo dopo che l'utente conferma la
 * proposta dall'interfaccia. Calcola l'allocazione (residuo di ogni documento)
 * esattamente come farebbe la riconciliazione vera.
 */
class ProposeReconciliationTool implements AssistantTool
{
    public function __construct(private readonly MovementReconciler $reconciler) {}

    public function name(): string
    {
        return 'propose_reconciliation';
    }

    public function description(): string
    {
        return 'Prepara una proposta di riconciliazione di un movimento bancario verso uno o più documenti '
            .'(fatture attive/passive, costi, spese). NON esegue: crea una proposta che l\'utente deve confermare. '
            .'Puoi indicare più documenti la cui somma torna con l\'importo del movimento. '
            .'Verifica prima con gli altri tool che movimento e documenti esistano e che gli importi tornino.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'movement_id' => ['type' => 'integer', 'description' => 'id del movimento bancario da riconciliare'],
                'targets' => [
                    'type' => 'array',
                    'description' => 'Documenti da agganciare',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['invoice', 'passive_invoice', 'costo', 'expense'], 'description' => 'invoice=fattura attiva, passive_invoice=fattura passiva, costo, expense=spesa'],
                            'id' => ['type' => 'integer'],
                        ],
                        'required' => ['type', 'id'],
                    ],
                ],
            ],
            'required' => ['movement_id', 'targets'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $tx = BankTransaction::find((int) ($input['movement_id'] ?? 0));
        if ($tx === null) {
            return AssistantToolResult::error('Movimento non trovato (id '.($input['movement_id'] ?? '').').');
        }

        $remaining = round($tx->unreconciledAmount(), 2);
        if ($remaining <= 0.01) {
            return AssistantToolResult::error('Il movimento id '.$tx->id.' è già interamente riconciliato: non c\'è nulla da agganciare.');
        }

        $targets = [];
        $allocated = 0.0;
        $left = $remaining;

        foreach ((array) ($input['targets'] ?? []) as $t) {
            $doc = $this->reconciler->resolve((string) ($t['type'] ?? ''), (int) ($t['id'] ?? 0));
            if ($doc === null) {
                return AssistantToolResult::error('Documento non trovato: '.json_encode($t).'.');
            }

            $residual = $this->reconciler->documentResidual($doc);
            $alloc = round(min($residual, $left), 2);
            if ($alloc <= 0.01) {
                continue;
            }

            $targets[] = [
                'type' => (string) $t['type'],
                'id' => (int) $t['id'],
                'amount' => $alloc,
                'label' => $this->reconciler->label($doc),
            ];
            $allocated = round($allocated + $alloc, 2);
            $left = round($left - $alloc, 2);
        }

        if ($targets === []) {
            return AssistantToolResult::error('Nessun documento con residuo da allocare: forse sono già pagati/riconciliati.');
        }

        $movementLabel = sprintf(
            '%s — € %s — %s',
            trim((string) ($tx->description ?: $tx->counterparty ?: 'Movimento')),
            number_format(abs((float) $tx->amount), 2, ',', '.'),
            optional($tx->booked_at)->format('d/m/Y') ?? '',
        );

        $action = [
            'type' => 'reconcile',
            'movement_id' => $tx->id,
            'movement_label' => $movementLabel,
            'targets' => $targets,
            'total' => $allocated,
            'remaining' => $left,
        ];

        $lines = collect($targets)->map(fn (array $t): string => '  · '.$t['label'].' → € '.number_format($t['amount'], 2, ',', '.'))->implode("\n");
        $torna = abs($allocated - $remaining) <= 0.01 ? ' (torna con l\'importo del movimento ✓)' : ' (⚠️ non copre tutto il movimento: residuo € '.number_format($left, 2, ',', '.').')';

        $content = "Proposta preparata (in attesa di conferma dell'utente, NON ancora scritta):\n"
            ."Movimento id {$tx->id}: {$movementLabel}\n"
            ."Documenti:\n{$lines}\n"
            .'Totale allocato: € '.number_format($allocated, 2, ',', '.').$torna."\n"
            ."Di' all'utente di confermare la proposta qui sotto per applicarla.";

        $summary = 'Proposta riconciliazione: mov. '.$tx->id.' → '.count($targets).' doc.';

        return AssistantToolResult::proposal($content, $summary, $action);
    }
}
