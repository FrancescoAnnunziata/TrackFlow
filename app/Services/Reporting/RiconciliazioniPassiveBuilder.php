<?php

namespace App\Services\Reporting;

use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Quadratura per fattura PASSIVA: ogni fattura ricevuta con accanto il movimento
 * (o i movimenti) bancario di pagamento, oppure l'indicazione del rimborso spese
 * quando è stata anticipata dal dipendente. Per il controllo del commercialista.
 * Le note di credito passive (documenti autonomi) sono escluse.
 */
class RiconciliazioniPassiveBuilder
{
    private const EPSILON = 0.01;

    public const HEADINGS = [
        'Numero',
        'Data',
        'Fornitore',
        'Totale',
        'Pagato',
        'Residuo',
        'Stato',
        'Pagamento',
        'Giustificativo',
    ];

    /** Colonne numeriche (allineate a destra e formattate in euro). */
    public const NUMERIC_COLUMNS = [3, 4, 5];

    /**
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $invoices = PassiveInvoice::with(['supplier', 'reimbursement', 'reconciliations.bankTransaction.bankAccount'])
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)
            ->whereBetween('document_date', [$from, $to])
            ->orderBy('document_date')
            ->orderBy('number')
            ->get();

        $rows = [];
        $totTotale = 0.0;
        $totPagato = 0.0;
        $totResiduo = 0.0;

        foreach ($invoices as $invoice) {
            $totale = $invoice->total();
            $pagato = $invoice->reconciledAmount();
            $residuo = round(max(0, $totale - $pagato), 2);

            // Le fatture estere (caricate a mano) sono rese esplicite nel numero.
            $numero = $invoice->number.(DocumentReference::isForeignPassive($invoice) ? ' (estera)' : '');

            $rows[] = ['kind' => 'data', 'cells' => [
                $numero,
                optional($invoice->document_date)->format('d/m/Y') ?? '',
                $invoice->supplier->name ?? '',
                $totale,
                $pagato,
                $residuo,
                $this->stato($invoice, $totale, $pagato),
                $this->pagamento($invoice),
                DocumentReference::linkCell($invoice),
            ]];

            $totTotale += $totale;
            $totPagato += $pagato;
            $totResiduo += $residuo;
        }

        if ($rows !== []) {
            $rows[] = ['kind' => 'total', 'cells' => [
                'TOTALE', '', '',
                round($totTotale, 2), round($totPagato, 2), round($totResiduo, 2),
                '', '', '',
            ]];
        }

        return ['headings' => self::HEADINGS, 'rows' => $rows];
    }

    public function export(CarbonInterface $from, CarbonInterface $to, string $format = 'xlsx'): BinaryFileResponse
    {
        $table = $this->build($from, $to);
        $rows = array_map(fn (array $r): array => $r['cells'], $table['rows']);

        $filename = sprintf('riconciliazioni-fatture-passive-%s_%s', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return SpreadsheetExporter::download($filename, $table['headings'], $rows, $format);
    }

    private function stato(PassiveInvoice $invoice, float $totale, float $pagato): string
    {
        if ($invoice->isPaidViaReimbursement()) {
            return 'Pagata (rimborso)';
        }

        if ($pagato + self::EPSILON >= $totale) {
            return 'Pagata';
        }

        return $pagato > self::EPSILON ? 'Parziale' : 'Da pagare';
    }

    private function pagamento(PassiveInvoice $invoice): string
    {
        $movimenti = $invoice->reconciliations
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

        // Se è stata anticipata dal dipendente e chiusa da un rimborso, lo
        // segnaliamo esplicitamente (non c'è un pagamento bancario diretto).
        if ($invoice->isPaidViaReimbursement()) {
            $r = $invoice->reimbursement;
            $etichetta = 'Rimborso spese'.($r && $r->date ? ' · '.$r->date->format('d/m/Y') : '');

            return $movimenti !== '' ? $etichetta."\n".$movimenti : $etichetta;
        }

        return $movimenti;
    }
}
