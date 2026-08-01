<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

class ReadActiveInvoicesTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_active_invoices';
    }

    public function description(): string
    {
        return 'Legge le fatture attive (emesse ai clienti) con filtri. Usalo per trovare la fattura incassata da un '
            .'movimento in entrata. Restituisce id, numero, data, cliente, totale, importo da incassare e stato.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client' => ['type' => 'string', 'description' => 'Nome (o parte) del cliente'],
                'search' => ['type' => 'string', 'description' => 'Testo nel numero fattura'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'paid'], 'description' => 'Stato: draft=bozza, sent=inviata, paid=pagata'],
                'collected' => ['type' => 'boolean', 'description' => 'true = già incassate, false = ancora da incassare'],
                'from_date' => ['type' => 'string', 'description' => 'Data emissione dal (YYYY-MM-DD)'],
                'until_date' => ['type' => 'string', 'description' => 'Data emissione al (YYYY-MM-DD)'],
                'limit' => ['type' => 'integer', 'description' => 'Massimo risultati (default 25, max 100)'],
            ],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

        $rows = Invoice::query()
            ->with('client')
            ->where('type', '!=', Invoice::TYPE_CREDIT_NOTE)
            ->when(filled($input['client'] ?? null), fn (Builder $q) => $q->whereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$input['client'].'%')))
            ->when(filled($input['search'] ?? null), fn (Builder $q) => $q->where('number', 'like', '%'.$input['search'].'%'))
            ->when(filled($input['status'] ?? null), fn (Builder $q) => $q->where('status', $input['status']))
            ->when(filled($input['from_date'] ?? null), fn (Builder $q) => $q->whereDate('issue_date', '>=', $input['from_date']))
            ->when(filled($input['until_date'] ?? null), fn (Builder $q) => $q->whereDate('issue_date', '<=', $input['until_date']))
            ->orderByDesc('issue_date')
            ->limit($limit)
            ->get();

        // Il filtro "incassata" dipende dai metodi calcolati, quindi si applica dopo il fetch.
        if (array_key_exists('collected', $input) && $input['collected'] !== null) {
            $wantCollected = (bool) $input['collected'];
            $rows = $rows->filter(fn (Invoice $i): bool => ($i->amountToCollect() <= 0.01) === $wantCollected)->values();
        }

        if ($rows->isEmpty()) {
            return AssistantToolResult::ok('Nessuna fattura attiva trovata con questi filtri.', 'Fatture attive: 0');
        }

        $lines = $rows->map(fn (Invoice $i): string => sprintf(
            '- id=%d | %s | %s | %s | tot € %s | da incassare € %s | %s',
            $i->id,
            $i->number ?: '(s.n.)',
            optional($i->issue_date)->format('d/m/Y') ?? '',
            $i->client->name ?? '—',
            number_format($i->total(), 2, ',', '.'),
            number_format($i->amountToCollect(), 2, ',', '.'),
            $i->status,
        ))->implode("\n");

        return AssistantToolResult::ok("Fatture attive ({$rows->count()}):\n".$lines, 'Fatture attive: '.$rows->count());
    }
}
