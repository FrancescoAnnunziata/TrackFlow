<?php

namespace App\Services\Reporting;

use App\Models\PassiveInvoice;
use App\Support\Paesi;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Elenco delle fatture passive estere di un periodo, per il commercialista che
 * le registra nell'esterometro (integrazione/autofattura TD17, TD18, TD19).
 *
 * «Estera» vuol dire fornitore con sede fuori dall'Italia e documento non
 * arrivato da Fatture in Cloud (vedi DocumentReference::isForeignDocument):
 * le integrazioni «N/UE» che il commercialista emette tornano su FiC col
 * fornitore estero, e qui non devono ricomparire. Le note di credito ci sono,
 * con importi negativi.
 */
class FattureEstereBuilder
{
    public const HEADINGS = [
        'Data',
        'Numero',
        'Tipo',
        'Fornitore',
        'Paese',
        'UE / Extra-UE',
        'Partita IVA fornitore',
        'Valuta originale',
        'Importo originale',
        'Imponibile (EUR)',
        'IVA (EUR)',
        'Totale (EUR)',
        'Integrazione',
        'Link al PDF',
    ];

    /** Colonne numeriche (per l'allineamento nell'anteprima). */
    public const NUMERIC_COLUMNS = [8, 9, 10, 11];

    /** Colonna del link (per renderla cliccabile nell'anteprima). */
    public const LINK_COLUMN = 13;

    /**
     * @return array{headings: array<int, string>, rows: array<int, array<int, mixed>>, totale: float}
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];
        $totale = 0.0;

        $invoices = PassiveInvoice::query()
            ->with(['supplier', 'integrations'])
            ->whereBetween('document_date', [$from, $to])
            // Stessa regola di DocumentReference::isForeignDocument, in SQL.
            ->whereNull('fic_document_id')
            ->whereHas('supplier', fn ($q) => $q->whereNotNull('country_iso')->where('country_iso', '!=', 'IT'))
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();

        foreach ($invoices as $p) {
            $sign = $p->isCreditNote() ? -1 : 1;
            $iso = strtoupper((string) $p->supplier->country_iso);
            $gross = round($sign * (float) $p->amount_gross, 2);
            $totale += $gross;

            $rows[] = [
                optional($p->document_date)->format('d/m/Y') ?? '',
                $p->number ?: '',
                $p->isCreditNote() ? 'Nota di credito' : 'Fattura',
                $p->supplier->name,
                Paesi::nome($iso) ?? $iso,
                Paesi::area($iso),
                $p->supplier->vat_number ?: '',
                $p->original_currency ?: ($p->currency ?: 'EUR'),
                $p->original_currency ? round($sign * (float) $p->original_amount, 2) : $gross,
                round($sign * (float) $p->amount_net, 2),
                round($sign * (float) $p->amount_vat, 2),
                $gross,
                // Già integrata dal commercialista? Così vede a colpo d'occhio cosa manca.
                $p->integrations->map(fn (PassiveInvoice $i): string => $i->number.' del '.$i->document_date?->format('d/m/Y'))->implode(', '),
                DocumentReference::attachmentUrl($p) ?? '',
            ];
        }

        return ['headings' => self::HEADINGS, 'rows' => $rows, 'totale' => round($totale, 2)];
    }

    /**
     * Integrazioni arrivate da FiC che non si sono potute collegare: di solito
     * la fattura estera non è ancora stata caricata da Fatture estere.
     *
     * @return array<int, PassiveInvoice>
     */
    public function unlinkedIntegrations(): array
    {
        return PassiveInvoice::conIntegrazioni()
            ->with('supplier')
            ->whereNotNull('integration_type')
            ->whereNull('integrates_id')
            ->orderBy('document_date')
            ->get()
            ->all();
    }

    public function export(CarbonInterface $from, CarbonInterface $to, string $format = 'xlsx'): BinaryFileResponse
    {
        $table = $this->build($from, $to);
        $filename = sprintf('fatture-estere-%s_%s', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return SpreadsheetExporter::download($filename, $table['headings'], $table['rows'], $format);
    }
}
