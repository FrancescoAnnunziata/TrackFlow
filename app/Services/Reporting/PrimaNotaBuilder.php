<?php

namespace App\Services\Reporting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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
        'Link documento',
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
                    // Un giroconto è "chiuso" pur non essendo riconciliato a un
                    // documento: lo distinguiamo dal Sì/No delle riconciliazioni.
                    $tx->isTransfer() ? 'Giroconto' : ($tx->reconciled ? 'Sì' : 'No'),
                    $this->documentLabel($tx),
                    $this->documentUrl($tx),
                ]];
            }

            $rows[] = ['kind' => 'subtotal', 'cells' => [
                '', $account->name, 'Saldo finale', '', null, null, $running, '', '', '',
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
            $pair = $tx->transferPair;
            $other = $pair?->bankAccount?->name;

            if ($other === null) {
                return 'Giroconto';
            }

            // Identifica il movimento gemello con conto, direzione e data, così
            // nel report è tracciabile a quale trasferimento corrisponde.
            $data = optional($pair->booked_at)->format('d/m/Y');

            return sprintf(
                'Giroconto (%s%s%s)',
                $tx->amount < 0 ? '→ ' : '← ',
                $other,
                $data ? ' · '.$data : '',
            );
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
            // Un costo generico creato dal movimento non ha un numero documento:
            // basta indicarlo come "Costo" (con il conto, se presente).
            $model instanceof Costo => 'Costo'.($model->category ? ' · '.$model->category : ''),
            $model instanceof Reimbursement => 'Rimborso spese'.($model->notes ? ': '.$model->notes : ''),
            $model instanceof Expense => 'Spesa: '.($model->supplier->name ?? $model->notes ?? (optional($model->date)->format('d/m/Y') ?? '')),
            default => null,
        };
    }

    /**
     * Link della colonna "Link documento": il PDF/giustificativo caricato, così
     * si apre il documento vero. Se il documento non ha un PDF in locale (es. i
     * costi senza giustificativo, o le fatture importate da FiC il cui PDF sta
     * su Fatture in Cloud) la cella resta vuota. Vuoto anche per i giroconti.
     */
    private function documentUrl(BankTransaction $tx): string
    {
        if ($tx->isTransfer()) {
            return '';
        }

        return $this->attachmentUrl($tx->reconciliations->first()?->reconcilable) ?? '';
    }

    /**
     * URL del giustificativo (PDF/foto) allegato al documento, se presente sul
     * disco public. I diversi tipi lo memorizzano in campi diversi.
     */
    private function attachmentUrl(?Model $model): ?string
    {
        $paths = match (true) {
            $model instanceof PassiveInvoice => [$model->attachment],
            $model instanceof Costo, $model instanceof Reimbursement => $model->attachments ?? [],
            $model instanceof Expense => $model->attachaments ?? [],
            default => [],
        };

        $paths = array_values(array_filter($paths, fn ($p): bool => is_string($p) && $p !== ''));

        if ($paths === []) {
            return null;
        }

        foreach ($paths as $path) {
            if (str_ends_with(strtolower($path), '.pdf')) {
                return Storage::disk('public')->url($path);
            }
        }

        return Storage::disk('public')->url($paths[0]);
    }
}
