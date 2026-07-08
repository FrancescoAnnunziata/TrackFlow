<?php

namespace App\Services\Reporting;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Genera un file scaricabile (XLSX o CSV) da intestazioni + righe, usando
 * openspout. Scrive su file temporaneo e lo restituisce come download,
 * cancellandolo dopo l'invio.
 */
class SpreadsheetExporter
{
    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, mixed>>  $rows  righe già in ordine di colonna
     */
    public static function download(string $filename, array $headings, iterable $rows, string $format = 'xlsx'): BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';

        $path = tempnam(sys_get_temp_dir(), 'export_').'.'.$format;

        $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headings));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values($row)));
        }

        $writer->close();

        return response()
            ->download($path, $filename.'.'.$format)
            ->deleteFileAfterSend(true);
    }
}
