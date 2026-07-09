<?php

namespace App\Services\Reporting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Prima nota (banca): registro cronologico dei movimenti bancari del periodo,
 * con saldo progressivo per conto e, dove riconciliato, il documento collegato.
 * Esportabile in Excel/CSV per il commercialista.
 */
class PrimaNotaBuilder
{
    public const HEADINGS = [
        'Data',
        'Conto',
        'Descrizione',
        'Controparte',
        'Entrata',
        'Uscita',
        'Saldo',
        'Riconciliato',
        'Documento',
    ];

    /**
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?int $bankAccountId = null): array
    {
        $accounts = BankAccount::query()
            ->when($bankAccountId, fn ($q) => $q->whereKey($bankAccountId))
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $running = $this->openingBalance($account, $from);

            $transactions = $account->transactions()
                ->with(['reconciliations.reconcilable', 'transferPair.bankAccount'])
                ->whereBetween('booked_at', [$from, $to])
                ->orderBy('booked_at')
                ->orderBy('id')
                ->get();

            if ($transactions->isEmpty()) {
                continue;
            }

            foreach ($transactions as $tx) {
                $amount = round((float) $tx->amount, 2);
                $running = round($running + $amount, 2);

                $rows[] = ['kind' => 'data', 'cells' => [
                    optional($tx->booked_at)->format('d/m/Y') ?? '',
                    $account->name,
                    $tx->description,
                    $tx->counterparty,
                    $amount > 0 ? $amount : null,
                    $amount < 0 ? abs($amount) : null,
                    $running,
                    $tx->reconciled ? 'Sì' : 'No',
                    $this->documentLabel($tx),
                ]];
            }

            $rows[] = ['kind' => 'subtotal', 'cells' => [
                '', $account->name, 'Saldo finale', '', null, null, $running, '', '',
            ]];
        }

        return ['headings' => self::HEADINGS, 'rows' => $rows];
    }

    public function export(CarbonInterface $from, CarbonInterface $to, ?int $bankAccountId = null, string $format = 'xlsx'): BinaryFileResponse
    {
        $table = $this->build($from, $to, $bankAccountId);
        $rows = array_map(fn (array $r): array => $r['cells'], $table['rows']);

        $filename = sprintf('prima-nota-%s_%s', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return SpreadsheetExporter::download($filename, $table['headings'], $rows, $format);
    }

    /**
     * Saldo del conto all'inizio del periodo: saldo di apertura + somma dei
     * movimenti precedenti (dalla data di apertura, se impostata).
     */
    private function openingBalance(BankAccount $account, CarbonInterface $from): float
    {
        $base = (float) $account->opening_balance;

        $prior = $account->transactions()
            ->where('booked_at', '<', $from)
            ->when(
                $account->opening_balance_date,
                fn ($q) => $q->where('booked_at', '>=', $account->opening_balance_date),
            )
            ->sum('amount');

        return round($base + (float) $prior, 2);
    }

    /**
     * Etichetta breve della causale del movimento: "Giroconto" se è uno
     * spostamento tra conti propri, altrimenti i documenti riconciliati.
     */
    private function documentLabel(BankTransaction $tx): string
    {
        if ($tx->isTransfer()) {
            $other = $tx->transferPair?->bankAccount?->name;

            return 'Giroconto'.($other ? ' ('.($tx->amount < 0 ? '→ ' : '← ').$other.')' : '');
        }

        return $tx->reconciliations
            ->map(fn ($rec): ?string => $this->modelLabel($rec->reconcilable))
            ->filter()
            ->implode(', ');
    }

    private function modelLabel(?Model $model): ?string
    {
        return match (true) {
            $model instanceof Invoice => 'Fattura '.$model->number,
            $model instanceof PassiveInvoice => 'Fatt. passiva '.($model->number ?: '—'),
            $model instanceof Costo => 'Costo: '.$model->description,
            $model instanceof Expense => 'Spesa: '.($model->supplier->name ?? $model->notes ?? (optional($model->date)->format('d/m/Y') ?? '')),
            default => null,
        };
    }
}
