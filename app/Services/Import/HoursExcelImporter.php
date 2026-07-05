<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\Hour;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Importa un timesheet ore da file Excel (.xlsx) con colonne mappabili per
 * nome intestazione. Parsing senza dipendenze (ZipArchive + SimpleXML).
 *
 * columnMap: ['date'=>'Data', 'client'=>'Cliente', 'hours'=>'Ore', 'notes'=>'Attività'].
 */
class HoursExcelImporter
{
    /**
     * @param  array<string, string>  $columnMap
     * @return array{imported: int, skipped: int, unmatched: array<int, string>}
     */
    public function import(string $path, int $userId, bool $billable, array $columnMap, ?int $forceClientId = null): array
    {
        return $this->importRows($this->readRows($path), $userId, $billable, $columnMap, $forceClientId);
    }

    /**
     * @param  array<int, array<string, string>>  $rows  Righe (colonna-lettera => valore); la prima è l'intestazione.
     * @param  array<string, string>  $columnMap
     * @return array{imported: int, skipped: int, unmatched: array<int, string>}
     */
    public function importRows(array $rows, int $userId, bool $billable, array $columnMap, ?int $forceClientId = null): array
    {
        if (count($rows) < 2) {
            return ['imported' => 0, 'skipped' => 0, 'unmatched' => []];
        }

        $header = array_shift($rows);
        $cols = [];
        foreach ($columnMap as $field => $headerName) {
            $cols[$field] = $this->findColumn($header, $headerName);
        }

        if (blank($cols['date'] ?? null) || blank($cols['hours'] ?? null)) {
            throw new RuntimeException('Colonne Data/Ore non trovate nel file: controlla la mappatura.');
        }

        $imported = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $dateRaw = $row[$cols['date']] ?? null;
            $hoursRaw = $row[$cols['hours']] ?? null;

            // Riga vuota: salta senza contarla.
            if (blank($dateRaw) && blank($hoursRaw)) {
                continue;
            }

            $date = $this->excelDate($dateRaw);
            $hours = (float) str_replace(',', '.', (string) $hoursRaw);

            if ($date === null || $hours <= 0) {
                $skipped++;

                continue;
            }

            $clientId = $forceClientId;
            if ($clientId === null && filled($cols['client'] ?? null)) {
                $name = trim((string) ($row[$cols['client']] ?? ''));
                $client = $this->matchClient($name);
                if ($client === null) {
                    $skipped++;
                    if ($name !== '') {
                        $unmatched[$name] = true;
                    }

                    continue;
                }
                $clientId = $client->id;
            }

            if ($clientId === null) {
                $skipped++;

                continue;
            }

            $notes = filled($cols['notes'] ?? null) ? trim((string) ($row[$cols['notes']] ?? '')) : null;

            $hour = Hour::create([
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'hours' => $hours,
                'notes' => $notes ?: null,
                'billable' => $billable,
            ]);
            $hour->clients()->attach($clientId);
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'unmatched' => array_keys($unmatched)];
    }

    /**
     * Converte un valore data di Excel: numero seriale (base 1899-12-30) oppure
     * stringa parsabile.
     */
    public function excelDate(mixed $value): ?Carbon
    {
        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        try {
            return Carbon::parse($s);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Legge le righe del primo foglio come [colonna-lettera => valore].
     *
     * @return array<int, array<string, string>>
     */
    public function readRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Impossibile aprire il file Excel.');
        }

        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = simplexml_load_string($ssXml);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string) $si->t;
                    } else {
                        $text = '';
                        foreach ($si->r as $run) {
                            $text .= (string) $run->t;
                        }
                        $shared[] = $text;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Foglio di lavoro non trovato nel file.');
        }

        $sheet = simplexml_load_string($sheetXml);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/[0-9]/', '', (string) $c['r']);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $cells[$col] = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $cells[$col] = (string) $c->is->t;
                } else {
                    $cells[$col] = (string) $c->v;
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $header
     */
    private function findColumn(array $header, string $name): ?string
    {
        $name = mb_strtolower(trim($name));
        foreach ($header as $col => $text) {
            if (mb_strtolower(trim((string) $text)) === $name) {
                return $col;
            }
        }

        return null;
    }

    private function matchClient(string $name): ?Client
    {
        if ($name === '') {
            return null;
        }

        $lower = mb_strtolower($name);

        return Client::whereRaw('LOWER(name) = ?', [$lower])->first()
            ?? Client::whereRaw('LOWER(name) LIKE ?', ['%'.$lower.'%'])->first();
    }
}
