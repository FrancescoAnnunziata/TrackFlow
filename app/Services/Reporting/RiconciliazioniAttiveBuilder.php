<?php

namespace App\Services\Reporting;

use App\Models\Invoice;
use App\Models\Reconciliation;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Quadratura per fattura ATTIVA: ogni fattura emessa con accanto il movimento
 * (o i movimenti) bancario di incasso e le note di credito collegate che ne
 * riducono l'importo da incassare. Per il controllo del commercialista.
 */
class RiconciliazioniAttiveBuilder
{
    private const EPSILON = 0.01;

    public const HEADINGS = [
        'Numero',
        'Data',
        'Cliente',
        'Totale',
        'Da incassare',
        'Incassato',
        'Residuo',
        'Stato',
        'Movimenti di incasso',
        'Note di credito',
        'Documento',
    ];

    /** Colonne numeriche (allineate a destra e formattate in euro). */
    public const NUMERIC_COLUMNS = [3, 4, 5, 6];

    /**
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = Invoice::with(['client', 'creditNotes', 'reconciliations.bankTransaction.bankAccount'])
            ->where('type', '!=', Invoice::TYPE_CREDIT_NOTE)
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        $rows = [];
        $totTotale = 0.0;
        $totDaIncassare = 0.0;
        $totIncassato = 0.0;
        $totResiduo = 0.0;

        foreach ($invoices as $invoice) {
            $totale = $invoice->total();
            $daIncassare = $invoice->amountToCollect();
            $incassato = $invoice->reconciledAmount();
            $residuo = round(max(0, $daIncassare - $incassato), 2);

            $rows[] = ['kind' => 'data', 'cells' => [
                $invoice->number,
                optional($invoice->issue_date)->format('d/m/Y') ?? '',
                $invoice->client->name ?? '',
                $totale,
                $daIncassare,
                $incassato,
                $residuo,
                $this->stato($daIncassare, $incassato),
                $this->movimenti($invoice),
                $this->noteDiCredito($invoice),
                DocumentReference::linkCell($invoice),
            ]];

            $totTotale += $totale;
            $totDaIncassare += $daIncassare;
            $totIncassato += $incassato;
            $totResiduo += $residuo;
        }

        if ($rows !== []) {
            $rows[] = ['kind' => 'total', 'cells' => [
                'TOTALE', '', '',
                round($totTotale, 2), round($totDaIncassare, 2), round($totIncassato, 2), round($totResiduo, 2),
                '', '', '', '',
            ]];
        }

        return ['headings' => self::HEADINGS, 'rows' => $rows];
    }

    public function export(CarbonInterface $from, CarbonInterface $to, string $format = 'xlsx'): BinaryFileResponse
    {
        $table = $this->build($from, $to);
        $rows = array_map(fn (array $r): array => $r['cells'], $table['rows']);

        $filename = sprintf('riconciliazioni-fatture-attive-%s_%s', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return SpreadsheetExporter::download($filename, $table['headings'], $rows, $format);
    }

    private function stato(float $daIncassare, float $incassato): string
    {
        if ($incassato + self::EPSILON >= $daIncassare) {
            return 'Incassata';
        }

        return $incassato > self::EPSILON ? 'Parziale' : 'Aperta';
    }

    private function movimenti(Invoice $invoice): string
    {
        return $invoice->reconciliations
            ->map(function (Reconciliation $r): ?string {
                $tx = $r->bankTransaction;
                if ($tx === null) {
                    return null;
                }

                return sprintf(
                    '%s · %s · € %s',
                    optional($tx->booked_at)->format('d/m/Y') ?? '',
                    $tx->bankAccount->name ?? '',
                    number_format((float) $r->amount, 2, ',', '.'),
                );
            })
            ->filter()
            ->implode("\n");
    }

    private function noteDiCredito(Invoice $invoice): string
    {
        return $invoice->creditNotes
            ->map(fn (Invoice $n): string => sprintf('%s · € %s', $n->number, number_format($n->total(), 2, ',', '.')))
            ->implode("\n");
    }
}
