<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Assistant\LinkDocumento;
use App\Models\Abbonamento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Legge gli abbonamenti fornitori trimestrali e annuali con la data del
 * prossimo rinnovo. Esposto a tutti i ruoli dell'assistente, commercialista
 * compreso: decisione esplicita di Giorgio del 28/11/2026.
 */
class ReadAbbonamentiTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_subscriptions';
    }

    public function description(): string
    {
        return 'Legge gli ABBONAMENTI ai fornitori che si rinnovano da soli ogni tre mesi o ogni anno (software, domini, '
            .'hosting), con cadenza, importo dell\'ultimo rinnovo, data del prossimo rinnovo e note. Usalo per domande come '
            .'«cosa si rinnova a marzo?» o «quanto spendiamo all\'anno in abbonamenti?», e per sapere se una spesa è già '
            .'segnata come abbonamento prima di proporlo. I costi mensili (AWS, Google Workspace, Claude…) qui NON ci sono: '
            .'per quelli guarda i movimenti bancari.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Testo nel nome dell\'abbonamento o del fornitore'],
                'renewal_from' => ['type' => 'string', 'description' => 'Prossimo rinnovo dal (YYYY-MM-DD)'],
                'renewal_until' => ['type' => 'string', 'description' => 'Prossimo rinnovo al (YYYY-MM-DD)'],
            ],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $search = trim((string) ($input['search'] ?? ''));

        $rows = Abbonamento::query()
            ->with('supplier')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.$search.'%')
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', '%'.$search.'%'))))
            ->when(filled($input['renewal_from'] ?? null), fn (Builder $q) => $q->whereDate('next_renewal_on', '>=', $input['renewal_from']))
            ->when(filled($input['renewal_until'] ?? null), fn (Builder $q) => $q->whereDate('next_renewal_on', '<=', $input['renewal_until']))
            ->orderBy('next_renewal_on')
            ->get();

        if ($rows->isEmpty()) {
            return AssistantToolResult::ok('Nessun abbonamento trovato con questi filtri.', 'Abbonamenti: 0');
        }

        $lines = $rows->map(fn (Abbonamento $a): string => sprintf(
            '- id=%d | %s | fornitore: %s | %s | € %s | prossimo rinnovo %s | ultimo addebito %s%s | %s',
            $a->id,
            $a->name,
            $a->supplier->name ?? '—',
            mb_strtolower($a->cadenceLabel()),
            $a->amount !== null ? number_format((float) $a->amount, 2, ',', '.') : '?',
            $a->next_renewal_on->format('d/m/Y'),
            $a->last_charged_on?->format('d/m/Y') ?? '—',
            filled($a->notes) ? ' | note: '.$a->notes : '',
            LinkDocumento::abbonamento($a->id),
        ))->implode("\n");

        $annual = $rows->sum(fn (Abbonamento $a): float => (float) $a->amount * (12 / Abbonamento::monthsFor($a->cadence)));

        return AssistantToolResult::ok(
            "Abbonamenti ({$rows->count()}), spesa annua stimata € ".number_format($annual, 2, ',', '.').":\n".$lines,
            'Abbonamenti: '.$rows->count(),
        );
    }
}
