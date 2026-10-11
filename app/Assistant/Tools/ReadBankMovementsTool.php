<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Assistant\LinkDocumento;
use App\Models\BankTransaction;
use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Builder;

class ReadBankMovementsTool implements AssistantTool
{
    public function name(): string
    {
        return 'read_bank_movements';
    }

    public function description(): string
    {
        return 'Legge i movimenti bancari con filtri. Usalo per cercare un movimento (es. un addebito da riconciliare), '
            .'o per elencare le uscite/entrate non ancora riconciliate. Restituisce id, data, importo, conto, descrizione e stato; '
            .'per i movimenti riconciliati anche i documenti collegati (fattura, fattura passiva, costo con il suo Conto, '
            .'rimborso spese), con la quota, l\'id e il link. Usalo per sapere a cosa è stato chiuso un movimento, es. un F24.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Testo nella descrizione o controparte'],
                'direction' => ['type' => 'string', 'enum' => ['in', 'out'], 'description' => 'in = entrate, out = uscite'],
                'reconciled' => ['type' => 'boolean', 'description' => 'true = solo riconciliati, false = solo NON riconciliati'],
                'min_amount' => ['type' => 'number', 'description' => 'Importo minimo (valore assoluto)'],
                'max_amount' => ['type' => 'number', 'description' => 'Importo massimo (valore assoluto)'],
                'from_date' => ['type' => 'string', 'description' => 'Data dal (YYYY-MM-DD)'],
                'until_date' => ['type' => 'string', 'description' => 'Data al (YYYY-MM-DD)'],
                'limit' => ['type' => 'integer', 'description' => 'Massimo risultati (default 25, max 100)'],
            ],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));

        $rows = BankTransaction::query()
            ->with(['bankAccount', 'reconciliations.reconcilable'])
            ->when(filled($input['search'] ?? null), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('description', 'like', '%'.$input['search'].'%')
                ->orWhere('counterparty', 'like', '%'.$input['search'].'%')))
            ->when(($input['direction'] ?? null) === 'in', fn (Builder $q) => $q->where('amount', '>', 0))
            ->when(($input['direction'] ?? null) === 'out', fn (Builder $q) => $q->where('amount', '<', 0))
            ->when(array_key_exists('reconciled', $input) && $input['reconciled'] !== null, fn (Builder $q) => $q->where('reconciled', (bool) $input['reconciled']))
            ->when(filled($input['min_amount'] ?? null), fn (Builder $q) => $q->whereRaw('ABS(amount) >= ?', [(float) $input['min_amount']]))
            ->when(filled($input['max_amount'] ?? null), fn (Builder $q) => $q->whereRaw('ABS(amount) <= ?', [(float) $input['max_amount']]))
            ->when(filled($input['from_date'] ?? null), fn (Builder $q) => $q->whereDate('booked_at', '>=', $input['from_date']))
            ->when(filled($input['until_date'] ?? null), fn (Builder $q) => $q->whereDate('booked_at', '<=', $input['until_date']))
            ->orderByDesc('booked_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return AssistantToolResult::ok('Nessun movimento trovato con questi filtri.', 'Movimenti: 0');
        }

        $lines = $rows->map(fn (BankTransaction $t): string => sprintf(
            '- id=%d | %s | € %s | %s | %s | %s | %s',
            $t->id,
            optional($t->booked_at)->format('d/m/Y') ?? '',
            number_format((float) $t->amount, 2, ',', '.'),
            $t->bankAccount->name ?? '—',
            mb_substr((string) $t->description, 0, 60),
            $t->reconciled ? 'riconciliato' : 'da riconciliare',
            LinkDocumento::movimento($t->id),
        ).$this->documentiCollegati($t))->implode("\n");

        return AssistantToolResult::ok("Movimenti ({$rows->count()}):\n".$lines, 'Movimenti bancari: '.$rows->count());
    }

    /**
     * Righe con i documenti a cui il movimento è riconciliato: senza, l'assistente
     * sa che un F24 è chiuso ma non a quale costo, né con quale Conto.
     */
    private function documentiCollegati(BankTransaction $t): string
    {
        return $t->reconciliations->map(fn (Reconciliation $r): string => sprintf(
            "\n    collegato a: %s | € %s | id=%s | %s",
            BankTransaction::describeReconcilable($r->reconcilable),
            number_format((float) $r->amount, 2, ',', '.'),
            $r->reconcilable?->getKey() ?? '—',
            LinkDocumento::documento($r->reconcilable),
        ))->implode('');
    }
}
