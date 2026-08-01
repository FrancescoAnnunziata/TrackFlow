<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\Expense;
use App\Models\Invoice;

/**
 * Dato l'id di una fattura attiva, elenca le SPESE riaddebitate (rimborsi art.15)
 * agganciate e, per ciascuna, la FATTURA PASSIVA collegata. Risponde a domande
 * come "quali fatture passive corrispondono alle spese riaddebitate in fattura X".
 */
class ReadInvoiceExpensesTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_invoice_reimbursed_expenses';
    }

    public function description(): string
    {
        return 'Dato l\'id di una fattura attiva, elenca le spese riaddebitate al cliente (rimborsi in art. 15) '
            .'agganciate alla fattura e, per ciascuna, la fattura passiva d\'acquisto collegata (se presente). '
            .'Usalo per capire, di una fattura emessa, quali fatture passive stanno dietro ai rimborsi spese.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'integer', 'description' => 'id della fattura attiva'],
            ],
            'required' => ['invoice_id'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $invoice = Invoice::with(['client', 'expenses.supplier', 'expenses.passiveInvoice.supplier'])
            ->find((int) ($input['invoice_id'] ?? 0));

        if ($invoice === null) {
            return AssistantToolResult::error('Fattura attiva non trovata (id '.($input['invoice_id'] ?? '').').');
        }

        $expenses = $invoice->expenses;
        $header = sprintf('Fattura %s — %s.', $invoice->number ?: '(s.n.)', $invoice->client->name ?? '—');

        if ($expenses->isEmpty()) {
            return AssistantToolResult::ok($header.' Nessuna spesa riaddebitata (rimborso art.15) agganciata.', 'Fattura '.($invoice->number ?: $invoice->id).': 0 rimborsi');
        }

        $lines = $expenses->map(function (Expense $e): string {
            $passive = $e->passiveInvoice;
            $link = $passive
                ? sprintf('Fattura passiva %s — %s (€ %s)', $passive->number ?: '(s.n.)', $passive->supplier->name ?? '—', number_format((float) $passive->amount_gross, 2, ',', '.'))
                : 'nessuna fattura passiva collegata';

            return sprintf(
                '- spesa id=%d | %s | € %s | %s → %s',
                $e->id,
                optional($e->date)->format('d/m/Y') ?? '',
                number_format((float) $e->amount, 2, ',', '.'),
                $e->supplier->name ?? ($e->notes ?? '—'),
                $link,
            );
        })->implode("\n");

        $total = number_format((float) $expenses->sum('amount'), 2, ',', '.');

        return AssistantToolResult::ok(
            $header." Spese riaddebitate ({$expenses->count()}, totale € {$total}):\n".$lines,
            'Rimborsi fattura '.($invoice->number ?: $invoice->id).': '.$expenses->count(),
        );
    }
}
