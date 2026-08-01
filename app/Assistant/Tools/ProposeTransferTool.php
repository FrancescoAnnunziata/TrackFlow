<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\BankTransaction;

/**
 * Prepara una PROPOSTA per segnare un movimento come GIROCONTO / PARTITA DI GIRO,
 * indicando uno o più movimenti collegati (le altre metà). Supporta il caso 1↔1
 * (un'uscita e un'entrata fra due conti) e l'uno-a-molti (es. un rimborso a
 * fronte di più uscite). NON scrive: la conferma la dà l'utente.
 */
class ProposeTransferTool implements AssistantTool
{
    public function name(): string
    {
        return 'propose_mark_as_transfer';
    }

    public function description(): string
    {
        return 'Prepara una proposta per segnare dei movimenti come giroconto / partita di giro (spostamento di '
            .'liquidità o rimborso che si compensa, NON un costo né un ricavo). Indica il movimento principale e uno o '
            .'più movimenti COLLEGATI (twin_ids): insieme la somma degli importi deve tornare a ZERO (es. +279 a fronte '
            .'di −58, −58, −163). Usa più twin_ids per il caso uno-a-molti. NON esegue: crea una proposta che l\'utente '
            .'conferma. Cerca prima i movimenti con read_bank_movements.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'movement_id' => ['type' => 'integer', 'description' => 'id del movimento principale'],
                'twin_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'id dei movimenti collegati (le altre metà). Uno solo per un giroconto 1↔1, più di uno per una partita di giro uno-a-molti.',
                ],
            ],
            'required' => ['movement_id', 'twin_ids'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $tx = BankTransaction::with('bankAccount')->find((int) ($input['movement_id'] ?? 0));
        if ($tx === null) {
            return AssistantToolResult::error('Movimento principale non trovato.');
        }

        $twinIds = collect($input['twin_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $tx->id)
            ->unique()
            ->values();

        if ($twinIds->isEmpty()) {
            return AssistantToolResult::error('Indica almeno un movimento collegato (twin_ids).');
        }

        $twins = BankTransaction::with('bankAccount')->whereIn('id', $twinIds)->get();
        if ($twins->count() !== $twinIds->count()) {
            $missing = $twinIds->diff($twins->pluck('id'))->implode(', ');

            return AssistantToolResult::error('Movimenti collegati non trovati: id '.$missing.'.');
        }

        // Collection separata: prepend muterebbe $twins (che serve intatto sotto).
        $members = collect([$tx])->merge($twins);

        foreach ($members as $m) {
            if ($m->isTransfer()) {
                return AssistantToolResult::error('Il movimento id '.$m->id.' è già un giroconto / partita di giro.');
            }
            if ($m->reconciled) {
                return AssistantToolResult::error('Il movimento id '.$m->id.' è già riconciliato a un documento: non è un giroconto.');
            }
        }

        $hasEntrata = $members->contains(fn (BankTransaction $m): bool => (float) $m->amount > 0);
        $hasUscita = $members->contains(fn (BankTransaction $m): bool => (float) $m->amount < 0);
        if (! $hasEntrata || ! $hasUscita) {
            return AssistantToolResult::error('Una partita di giro deve avere sia entrate sia uscite: qui sono tutti dello stesso segno.');
        }

        $net = round($members->sum(fn (BankTransaction $m): float => (float) $m->amount), 2);
        $unbalanced = abs($net) > 0.01;

        $label = fn (BankTransaction $m): string => sprintf(
            'id %d — %s — %s — € %s — %s',
            $m->id,
            $m->bankAccount->name ?? '',
            (float) $m->amount >= 0 ? 'entrata' : 'uscita',
            number_format(abs((float) $m->amount), 2, ',', '.'),
            optional($m->booked_at)->format('d/m/Y') ?? '',
        );

        $action = [
            'type' => 'transfer',
            'movement_id' => $tx->id,
            'movement_label' => $label($tx),
            'twin_ids' => $twins->pluck('id')->all(),
            'twins' => $twins->map(fn (BankTransaction $m): array => ['id' => $m->id, 'label' => $label($m)])->all(),
            'net' => $net,
        ];

        $summary = $twins->count() === 1
            ? 'Proposta giroconto: mov. '.$tx->id.' ↔ '.$twins->first()->id
            : 'Proposta partita di giro: mov. '.$tx->id.' ↔ '.$twins->count().' movimenti';

        $lines = $members->map(fn (BankTransaction $m): string => '  · '.$label($m))->implode("\n");
        $warn = $unbalanced
            ? "\n⚠️ La somma NON torna a zero (sbilancio € ".number_format($net, 2, ',', '.').'): verifica che siano davvero tutti e soli i movimenti della partita.'
            : "\n✓ La somma torna a zero.";

        $content = "Proposta preparata (in attesa di conferma dell'utente, NON scritta):\n"
            ."Giroconto / partita di giro fra:\n".$lines.$warn."\n"
            ."Di' all'utente di confermare la proposta qui sotto per applicarla.";

        return AssistantToolResult::proposal($content, $summary, $action);
    }
}
