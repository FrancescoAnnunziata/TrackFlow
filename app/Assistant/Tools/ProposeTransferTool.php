<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\BankTransaction;

/**
 * Prepara una PROPOSTA per segnare un movimento come GIROCONTO, indicando il
 * movimento gemello (l'altra metà del trasferimento tra due conti propri).
 * NON scrive: la conferma la dà l'utente.
 */
class ProposeTransferTool implements AssistantTool
{
    public function name(): string
    {
        return 'propose_mark_as_transfer';
    }

    public function description(): string
    {
        return 'Prepara una proposta per segnare un movimento come giroconto (spostamento fra due conti propri), '
            .'indicando il movimento GEMELLO (stesso importo, segno opposto, su un altro conto). NON esegue: crea una '
            .'proposta che l\'utente conferma. Cerca prima il gemello con read_bank_movements (importo uguale, segno '
            .'opposto, stessa data circa).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'movement_id' => ['type' => 'integer', 'description' => 'id del movimento'],
                'twin_id' => ['type' => 'integer', 'description' => 'id del movimento gemello (altra metà del trasferimento)'],
            ],
            'required' => ['movement_id', 'twin_id'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $tx = BankTransaction::with('bankAccount')->find((int) ($input['movement_id'] ?? 0));
        $twin = BankTransaction::with('bankAccount')->find((int) ($input['twin_id'] ?? 0));

        if ($tx === null || $twin === null) {
            return AssistantToolResult::error('Movimento o gemello non trovato.');
        }
        if ($tx->id === $twin->id) {
            return AssistantToolResult::error('Il movimento e il gemello non possono essere lo stesso.');
        }
        foreach ([$tx, $twin] as $m) {
            if ($m->isTransfer()) {
                return AssistantToolResult::error('Il movimento id '.$m->id.' è già un giroconto.');
            }
            if ($m->reconciled) {
                return AssistantToolResult::error('Il movimento id '.$m->id.' è già riconciliato a un documento: non è un giroconto.');
            }
        }
        if (((float) $tx->amount > 0) === ((float) $twin->amount > 0)) {
            return AssistantToolResult::error('I due movimenti hanno lo stesso segno: un giroconto ha un\'uscita e un\'entrata.');
        }
        if ((int) $tx->bank_account_id === (int) $twin->bank_account_id) {
            return AssistantToolResult::error('I due movimenti sono sullo stesso conto: un giroconto è fra conti diversi.');
        }

        $mismatch = abs(abs((float) $tx->amount) - abs((float) $twin->amount)) > 0.01;

        $label = fn (BankTransaction $m): string => sprintf(
            '%s — %s — € %s — %s',
            $m->bankAccount->name ?? '',
            (float) $m->amount >= 0 ? 'entrata' : 'uscita',
            number_format(abs((float) $m->amount), 2, ',', '.'),
            optional($m->booked_at)->format('d/m/Y') ?? '',
        );

        $action = [
            'type' => 'transfer',
            'movement_id' => $tx->id,
            'movement_label' => $label($tx),
            'twin_id' => $twin->id,
            'twin_label' => $label($twin),
        ];

        $warn = $mismatch ? ' (⚠️ gli importi non coincidono esattamente: verifica che sia davvero lo stesso trasferimento)' : '';

        $content = "Proposta preparata (in attesa di conferma dell'utente, NON scritta):\n"
            ."Giroconto fra:\n  · id {$tx->id}: {$label($tx)}\n  · id {$twin->id}: {$label($twin)}".$warn."\n"
            ."Di' all'utente di confermare la proposta qui sotto per applicarla.";

        return AssistantToolResult::proposal($content, 'Proposta giroconto: mov. '.$tx->id.' ↔ '.$twin->id, $action);
    }
}
