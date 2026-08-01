<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Builder;

class ReadPassiveInvoicesTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_passive_invoices';
    }

    public function description(): string
    {
        return 'Legge le fatture passive (documenti d\'acquisto/costi ricevuti dai fornitori) con filtri. '
            .'Usalo per trovare la fattura passiva che un movimento in uscita ha pagato. Restituisce id, numero, data, fornitore, totale e stato pagamento.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier' => ['type' => 'string', 'description' => 'Nome (o parte) del fornitore'],
                'search' => ['type' => 'string', 'description' => 'Testo nel numero fattura'],
                'paid' => ['type' => 'boolean', 'description' => 'true = pagate, false = NON pagate'],
                'min_amount' => ['type' => 'number', 'description' => 'Totale minimo'],
                'max_amount' => ['type' => 'number', 'description' => 'Totale massimo'],
                'from_date' => ['type' => 'string', 'description' => 'Data documento dal (YYYY-MM-DD)'],
                'until_date' => ['type' => 'string', 'description' => 'Data documento al (YYYY-MM-DD)'],
                'limit' => ['type' => 'integer', 'description' => 'Massimo risultati (default 25, max 100)'],
            ],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

        $rows = PassiveInvoice::query()
            ->with('supplier')
            ->when(filled($input['supplier'] ?? null), fn (Builder $q) => $q->whereHas('supplier', fn (Builder $s) => $s->where('name', 'like', '%'.$input['supplier'].'%')))
            ->when(filled($input['search'] ?? null), fn (Builder $q) => $q->where('number', 'like', '%'.$input['search'].'%'))
            ->when(array_key_exists('paid', $input) && $input['paid'] !== null, fn (Builder $q) => (bool) $input['paid']
                ? $q->where('payment_status', PassiveInvoice::STATUS_PAID)
                : $q->where('payment_status', '!=', PassiveInvoice::STATUS_PAID))
            ->when(filled($input['min_amount'] ?? null), fn (Builder $q) => $q->where('amount_gross', '>=', (float) $input['min_amount']))
            ->when(filled($input['max_amount'] ?? null), fn (Builder $q) => $q->where('amount_gross', '<=', (float) $input['max_amount']))
            ->when(filled($input['from_date'] ?? null), fn (Builder $q) => $q->whereDate('document_date', '>=', $input['from_date']))
            ->when(filled($input['until_date'] ?? null), fn (Builder $q) => $q->whereDate('document_date', '<=', $input['until_date']))
            ->orderByDesc('document_date')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return AssistantToolResult::ok('Nessuna fattura passiva trovata con questi filtri.', 'Fatture passive: 0');
        }

        $lines = $rows->map(fn (PassiveInvoice $p): string => sprintf(
            '- id=%d | %s | %s | %s | € %s | %s',
            $p->id,
            $p->number ?: '(s.n.)',
            optional($p->document_date)->format('d/m/Y') ?? '',
            $p->supplier->name ?? '—',
            number_format((float) $p->amount_gross, 2, ',', '.'),
            $p->payment_status === PassiveInvoice::STATUS_PAID ? 'pagata' : 'non pagata',
        ))->implode("\n");

        return AssistantToolResult::ok("Fatture passive ({$rows->count()}):\n".$lines, 'Fatture passive: '.$rows->count());
    }
}
