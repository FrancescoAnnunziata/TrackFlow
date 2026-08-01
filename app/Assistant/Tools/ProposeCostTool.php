<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\BankTransaction;
use App\Services\Reconciliation\MovementActions;

/**
 * Prepara una PROPOSTA per chiudere un'uscita come COSTO diretto (senza fattura),
 * con la categoria/conto adeguato. NON scrive: la conferma la dà l'utente.
 */
class ProposeCostTool implements AssistantTool
{
    public function __construct(private readonly MovementActions $actions) {}

    public function name(): string
    {
        return 'propose_mark_as_cost';
    }

    public function description(): string
    {
        $cats = implode(', ', $this->actions->categories());

        return 'Prepara una proposta per segnare un movimento in USCITA come costo diretto (commissioni, imposte, '
            .'bolli, piccoli acquisti senza fattura), assegnando la categoria/conto giusto. NON esegue: crea una '
            .'proposta che l\'utente conferma. Usalo solo per uscite non ancora riconciliate e per cui non esiste '
            .'una fattura passiva. Categorie disponibili: '.($cats ?: '(nessuna ancora)').'.';
    }

    public function inputSchema(): array
    {
        $categories = $this->actions->categories();

        $category = ['type' => 'string', 'description' => 'Categoria/conto del costo'];
        if ($categories !== []) {
            $category['enum'] = $categories;
        }

        return [
            'type' => 'object',
            'properties' => [
                'movement_id' => ['type' => 'integer', 'description' => 'id del movimento in uscita'],
                'category' => $category,
                'description' => ['type' => 'string', 'description' => 'Descrizione del costo (opzionale; default: la descrizione del movimento)'],
            ],
            'required' => ['movement_id'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $tx = BankTransaction::with('bankAccount')->find((int) ($input['movement_id'] ?? 0));
        if ($tx === null) {
            return AssistantToolResult::error('Movimento non trovato (id '.($input['movement_id'] ?? '').').');
        }
        if ($tx->direction !== BankTransaction::DIRECTION_OUT) {
            return AssistantToolResult::error('Solo le USCITE possono essere segnate come costo (il movimento id '.$tx->id.' non è un\'uscita).');
        }
        if ($tx->isTransfer()) {
            return AssistantToolResult::error('Il movimento id '.$tx->id.' è un giroconto: non è un costo.');
        }
        if ($tx->unreconciledAmount() <= 0.01) {
            return AssistantToolResult::error('Il movimento id '.$tx->id.' è già riconciliato: non c\'è nulla da chiudere.');
        }

        $amount = round($tx->unreconciledAmount(), 2);
        $description = trim((string) ($input['description'] ?? '')) ?: (string) ($tx->description ?: 'Costo');
        $category = trim((string) ($input['category'] ?? '')) ?: null;

        $movementLabel = sprintf(
            '%s — %s — € %s — %s',
            trim((string) ($tx->description ?: $tx->counterparty ?: 'Movimento')),
            $tx->bankAccount->name ?? '',
            number_format(abs((float) $tx->amount), 2, ',', '.'),
            optional($tx->booked_at)->format('d/m/Y') ?? '',
        );

        $action = [
            'type' => 'cost',
            'movement_id' => $tx->id,
            'movement_label' => $movementLabel,
            'amount' => $amount,
            'category' => $category,
            'description' => mb_substr($description, 0, 120),
        ];

        $content = "Proposta preparata (in attesa di conferma dell'utente, NON scritta):\n"
            ."Segna come COSTO il movimento id {$tx->id}: {$movementLabel}\n"
            .'Descrizione: '.$action['description']."\n"
            .'Categoria: '.($category ?: '— (nessuna)')."\n"
            .'Importo: € '.number_format($amount, 2, ',', '.')."\n"
            ."Di' all'utente di confermare la proposta qui sotto per applicarla.";

        return AssistantToolResult::proposal($content, 'Proposta costo: mov. '.$tx->id.($category ? ' → '.$category : ''), $action);
    }
}
