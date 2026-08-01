<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Models\Reimbursement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Legge i documenti "Rimborso spese" (Reimbursement): le richieste di rimborso a
 * un dipendente/amministratore per spese anticipate. Servono all'assistente per
 * riconciliare il bonifico di rimborso al documento giusto (tipo reimbursement),
 * NON per crearli.
 */
class ReadReimbursementsTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_reimbursements';
    }

    public function description(): string
    {
        return 'Legge i documenti "Rimborso spese" (rimborsi a un dipendente/amministratore per spese anticipate). '
            .'Usalo per trovare il documento Rimborso a cui riconciliare un bonifico di rimborso in uscita. '
            .'Restituisce id, data, note, totale, quota già riconciliata e residuo da coprire. Per riconciliare, '
            .'usa propose_reconciliation con type "reimbursement".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Testo nelle note (es. mese o "km")'],
                'only_open' => ['type' => 'boolean', 'description' => 'true = solo con residuo ancora da riconciliare'],
                'min_amount' => ['type' => 'number', 'description' => 'Totale minimo'],
                'max_amount' => ['type' => 'number', 'description' => 'Totale massimo'],
                'from_date' => ['type' => 'string', 'description' => 'Data dal (YYYY-MM-DD)'],
                'until_date' => ['type' => 'string', 'description' => 'Data al (YYYY-MM-DD)'],
                'limit' => ['type' => 'integer', 'description' => 'Massimo risultati (default 25, max 100)'],
            ],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

        $rows = Reimbursement::query()
            ->when(filled($input['search'] ?? null), fn (Builder $q) => $q->where('notes', 'like', '%'.$input['search'].'%'))
            ->when(filled($input['min_amount'] ?? null), fn (Builder $q) => $q->where('amount', '>=', (float) $input['min_amount']))
            ->when(filled($input['max_amount'] ?? null), fn (Builder $q) => $q->where('amount', '<=', (float) $input['max_amount']))
            ->when(filled($input['from_date'] ?? null), fn (Builder $q) => $q->whereDate('date', '>=', $input['from_date']))
            ->when(filled($input['until_date'] ?? null), fn (Builder $q) => $q->whereDate('date', '<=', $input['until_date']))
            ->orderByDesc('date')
            ->limit($limit)
            ->get();

        // Filtro "solo aperti" a valle: il residuo dipende dalle riconciliazioni.
        if (! empty($input['only_open'])) {
            $rows = $rows->filter(fn (Reimbursement $r): bool => round($r->total() - $r->reconciledAmount(), 2) > 0.01)->values();
        }

        if ($rows->isEmpty()) {
            return AssistantToolResult::ok('Nessun rimborso spese trovato con questi filtri.', 'Rimborsi: 0');
        }

        $lines = $rows->map(function (Reimbursement $r): string {
            $residuo = round($r->total() - $r->reconciledAmount(), 2);

            return sprintf(
                '- id=%d | %s | € %s totale | riconciliato € %s | residuo € %s | %s',
                $r->id,
                optional($r->date)->format('d/m/Y') ?? '',
                number_format($r->total(), 2, ',', '.'),
                number_format($r->reconciledAmount(), 2, ',', '.'),
                number_format($residuo, 2, ',', '.'),
                str($r->notes ?: '')->limit(60)->value(),
            );
        })->implode("\n");

        return AssistantToolResult::ok("Rimborsi spese ({$rows->count()}):\n".$lines, 'Rimborsi: '.$rows->count());
    }
}
