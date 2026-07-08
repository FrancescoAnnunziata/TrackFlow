<?php

namespace App\Services\Reporting;

use App\Models\Costo;
use App\Models\Expense;
use App\Models\PassiveInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Costruisce il registro degli acquisti/spese per il commercialista: una riga
 * per costo (spese con scontrino, costi senza fattura, fatture passive non
 * collegate a una spesa), con conto contabile, giustificativo, fattura passiva
 * e fattura attiva di riaddebito. Raggruppato per conto con subtotali.
 */
class RegistroAcquistiBuilder
{
    public const HEADINGS = [
        'Tipo',
        'Data',
        'Fornitore',
        'Conto',
        'Cliente',
        'Imponibile',
        'IVA',
        'Totale',
        'Giustificativo',
        'Fattura passiva',
        'Riaddebito (fattura attiva)',
    ];

    /**
     * Tabella pronta per anteprima ed export.
     *
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $items = $this->expenseItems($from, $to)
            ->concat($this->costoItems($from, $to))
            ->concat($this->passiveItems($from, $to))
            ->sortBy([
                fn (array $a, array $b): int => strcmp($a['conto_sort'], $b['conto_sort']),
                fn (array $a, array $b): int => ($a['date']?->timestamp ?? 0) <=> ($b['date']?->timestamp ?? 0),
            ])
            ->values();

        $rows = [];
        $grandTotal = 0.0;

        foreach ($items->groupBy('conto_sort') as $contoSort => $group) {
            $label = $contoSort === '~' ? 'Senza conto' : $contoSort;
            $subtotal = 0.0;

            foreach ($group as $item) {
                $rows[] = ['kind' => 'data', 'cells' => $item['cells']];
                $subtotal += $item['totale'];
            }

            $rows[] = ['kind' => 'subtotal', 'cells' => $this->summaryCells('Subtotale '.$label, $subtotal)];
            $grandTotal += $subtotal;
        }

        if ($rows !== []) {
            $rows[] = ['kind' => 'total', 'cells' => $this->summaryCells('TOTALE', $grandTotal)];
        }

        return ['headings' => self::HEADINGS, 'rows' => $rows];
    }

    /**
     * Genera il file scaricabile (xlsx/csv) del registro.
     */
    public function export(CarbonInterface $from, CarbonInterface $to, string $format = 'xlsx'): BinaryFileResponse
    {
        $table = $this->build($from, $to);
        $rows = array_map(fn (array $r): array => $r['cells'], $table['rows']);

        $filename = sprintf('registro-acquisti-%s_%s', $from->format('Y-m-d'), $to->format('Y-m-d'));

        return SpreadsheetExporter::download($filename, $table['headings'], $rows, $format);
    }

    /**
     * @return Collection<int, array{conto_sort: string, date: ?CarbonInterface, totale: float, cells: array<int, mixed>}>
     */
    private function expenseItems(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Expense::with(['supplier', 'client', 'passiveInvoice.supplier', 'invoices'])
            ->whereBetween('date', [$from, $to])
            ->get()
            ->map(function (Expense $e): array {
                $conto = trim((string) $e->conto);
                $atts = $e->attachaments ?? [];
                $giustificativo = filled($atts) ? Storage::disk('public')->url($atts[0]) : '';
                $passive = $e->passiveInvoice;
                $passiva = $passive
                    ? trim(($passive->number ?: '—').' — '.($passive->supplier->name ?? ''), ' —')
                    : '';
                $riaddebito = $e->invoices->pluck('number')->filter()->implode(', ');

                // Con fattura passiva collegata usiamo lo split netto/IVA/lordo
                // del documento fiscale; altrimenti solo l'importo della spesa.
                $netto = $passive ? round((float) $passive->amount_net, 2) : null;
                $iva = $passive ? round((float) $passive->amount_vat, 2) : null;
                $totale = $passive ? round((float) $passive->amount_gross, 2) : round((float) $e->amount, 2);

                return [
                    'conto_sort' => $conto !== '' ? $conto : '~',
                    'date' => $e->date,
                    'totale' => $totale,
                    'cells' => [
                        'Spesa',
                        optional($e->date)->format('d/m/Y') ?? '',
                        $e->supplier->name ?? '',
                        $conto,
                        $e->client->name ?? '',
                        $netto,
                        $iva,
                        $totale,
                        $giustificativo,
                        $passiva,
                        $riaddebito,
                    ],
                ];
            });
    }

    /**
     * @return Collection<int, array{conto_sort: string, date: ?CarbonInterface, totale: float, cells: array<int, mixed>}>
     */
    private function costoItems(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Costo::with('supplier')
            ->whereBetween('date', [$from, $to])
            ->get()
            ->map(function (Costo $c): array {
                $conto = $this->contoLabel($c->category);
                $totale = round((float) $c->amount + (float) $c->vat_amount, 2);

                return [
                    'conto_sort' => $conto !== '' ? $conto : '~',
                    'date' => $c->date,
                    'totale' => $totale,
                    'cells' => [
                        'Costo',
                        optional($c->date)->format('d/m/Y') ?? '',
                        $c->supplier->name ?? '',
                        $conto,
                        '',
                        round((float) $c->amount, 2),
                        round((float) $c->vat_amount, 2),
                        $totale,
                        '',
                        '',
                        '',
                    ],
                ];
            });
    }

    /**
     * Fatture passive non collegate a una spesa (le altre appaiono già nella
     * riga della spesa, per non contare due volte lo stesso costo).
     *
     * @return Collection<int, array{conto_sort: string, date: ?CarbonInterface, totale: float, cells: array<int, mixed>}>
     */
    private function passiveItems(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $linked = Expense::whereNotNull('passive_invoice_id')->pluck('passive_invoice_id')->unique()->all();

        return PassiveInvoice::with('supplier')
            ->whereBetween('document_date', [$from, $to])
            ->whereNotIn('id', $linked)
            ->get()
            ->map(function (PassiveInvoice $p): array {
                $conto = $this->contoLabel($p->category);
                $totale = round((float) $p->amount_gross, 2);

                return [
                    'conto_sort' => $conto !== '' ? $conto : '~',
                    'date' => $p->document_date,
                    'totale' => $totale,
                    'cells' => [
                        'Fattura passiva',
                        optional($p->document_date)->format('d/m/Y') ?? '',
                        $p->supplier->name ?? '',
                        $conto,
                        '',
                        round((float) $p->amount_net, 2),
                        round((float) $p->amount_vat, 2),
                        $totale,
                        '',
                        $p->number ?: '',
                        '',
                    ],
                ];
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function summaryCells(string $label, float $total): array
    {
        return ['', '', '', $label, '', null, null, round($total, 2), '', '', ''];
    }

    private function contoLabel(?string $raw): string
    {
        // Il conto è la categoria di Fatture in Cloud (stringa), coerente fra
        // spese, costi e fatture passive: si usa così com'è per il raggruppamento.
        return trim((string) $raw);
    }
}
