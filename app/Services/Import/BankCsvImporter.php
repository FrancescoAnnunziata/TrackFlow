<?php

namespace App\Services\Import;

use App\Models\BankTransaction;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Importa i movimenti bancari da file CSV (estratto conto) con colonne
 * mappabili. Parsing senza dipendenze (fgetcsv). Gestisce i formati italiani
 * (virgola decimale, date gg/mm/aaaa) e le colonne Dare/Avere separate.
 *
 * options: [
 *   'delimiter'    => ';',            // separatore CSV
 *   'decimal'      => ',',            // separatore decimale
 *   'thousands'    => '.',            // separatore migliaia
 *   'date_format'  => 'd/m/Y',       // formato data
 *   'amount_mode'  => 'signed',      // 'signed' | 'dare_avere'
 *   'columns'      => ['booked_at'=>'Data', 'amount'=>'Importo', ...],
 * ]
 */
class BankCsvImporter
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{imported: int, skipped: int, duplicates: int}
     */
    public function import(string $path, int $bankAccountId, array $options): array
    {
        return $this->importRows($this->readRows($path, $options), $bankAccountId, $options);
    }

    /**
     * @param  array<int, array<int, string>>  $rows  La prima riga è l'intestazione.
     * @param  array<string, mixed>  $options
     * @return array{imported: int, skipped: int, duplicates: int}
     */
    public function importRows(array $rows, int $bankAccountId, array $options): array
    {
        if (count($rows) < 2) {
            return ['imported' => 0, 'skipped' => 0, 'duplicates' => 0];
        }

        $columns = (array) ($options['columns'] ?? []);
        $mode = $options['amount_mode'] ?? 'signed';
        $header = array_shift($rows);

        $idx = [];
        foreach ($columns as $field => $headerName) {
            $idx[$field] = filled($headerName) ? $this->findColumn($header, (string) $headerName) : null;
        }

        if (! isset($idx['booked_at']) || $idx['booked_at'] === null) {
            throw new RuntimeException('Colonna Data non trovata nel file: controlla la mappatura.');
        }

        $amountAvailable = $mode === 'dare_avere'
            ? ($idx['dare'] !== null || $idx['avere'] !== null)
            : ($idx['amount'] ?? null) !== null;

        if (! $amountAvailable) {
            throw new RuntimeException('Colonna importo (o Dare/Avere) non trovata: controlla la mappatura.');
        }

        $imported = 0;
        $skipped = 0;
        $duplicates = 0;

        // Per le banche senza ID transazione univoco, due movimenti realmente
        // distinti ma con stessa data/importo/descrizione collidono. Contiamo le
        // occorrenze identiche nel file e le distinguiamo con un indice, così i
        // movimenti ripetuti si importano tutti mentre ri-caricare lo stesso
        // file resta idempotente.
        $occurrences = [];

        foreach ($rows as $row) {
            $dateRaw = $this->cell($row, $idx['booked_at'] ?? null);

            // Riga vuota: salta senza contarla.
            if (blank($dateRaw) && blank(implode('', $row))) {
                continue;
            }

            $bookedAt = $this->parseDate($dateRaw, (string) ($options['date_format'] ?? 'd/m/Y'));
            $amount = $this->resolveAmount($row, $idx, $mode, $options);

            if ($bookedAt === null || $amount === null) {
                $skipped++;

                continue;
            }

            $description = trim((string) $this->cell($row, $idx['description'] ?? null));
            $counterparty = trim((string) $this->cell($row, $idx['counterparty'] ?? null));
            $reference = trim((string) $this->cell($row, $idx['reference'] ?? null));
            $valueDate = $this->parseDate($this->cell($row, $idx['value_date'] ?? null), (string) ($options['date_format'] ?? 'd/m/Y'));

            // Dedup sempre content-based (data|importo|data valuta|descrizione) +
            // contatore di occorrenza, così un re-import dello stesso estratto (o di
            // uno più ampio che lo contiene) non duplica, a prescindere da come sono
            // mappate le colonne. Il contatore preserva i movimenti realmente
            // identici (stesso giorno, importo e descrizione). Il `reference`, se
            // presente, resta salvato ma NON entra nell'hash (era la causa dei
            // duplicati: mappato in un import e non nell'altro).
            $base = $bookedAt->format('Y-m-d').'|'.number_format($amount, 2, '.', '').'|'
                .($valueDate?->format('Y-m-d') ?? '').'|'.mb_strtolower($description);
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $dedupHash = sha1($base.'#'.$occurrences[$base]);

            $transaction = BankTransaction::firstOrCreate(
                [
                    'bank_account_id' => $bankAccountId,
                    'dedup_hash' => $dedupHash,
                ],
                [
                    'booked_at' => $bookedAt->format('Y-m-d'),
                    'value_date' => $valueDate?->format('Y-m-d'),
                    'amount' => $amount,
                    'direction' => $amount >= 0 ? BankTransaction::DIRECTION_IN : BankTransaction::DIRECTION_OUT,
                    'description' => $description ?: null,
                    'counterparty' => $counterparty ?: null,
                    'bank_reference' => $reference ?: null,
                    'raw' => $row,
                ],
            );

            $transaction->wasRecentlyCreated ? $imported++ : $duplicates++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'duplicates' => $duplicates];
    }

    /**
     * Ricava l'importo con segno dalla riga secondo la modalità.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int|null>  $idx
     * @param  array<string, mixed>  $options
     */
    private function resolveAmount(array $row, array $idx, string $mode, array $options): ?float
    {
        if ($mode === 'dare_avere') {
            $dare = $this->parseAmount($this->cell($row, $idx['dare'] ?? null), $options);
            $avere = $this->parseAmount($this->cell($row, $idx['avere'] ?? null), $options);

            if ($dare === null && $avere === null) {
                return null;
            }

            // Avere = entrata (+), Dare = uscita (-). Gli importi in colonna sono
            // sempre positivi: applichiamo noi il segno.
            return round(abs($avere ?? 0) - abs($dare ?? 0), 2);
        }

        return $this->parseAmount($this->cell($row, $idx['amount'] ?? null), $options);
    }

    /**
     * Converte un importo in formato italiano/europeo in float, con segno.
     *
     * @param  array<string, mixed>  $options
     */
    public function parseAmount(mixed $value, array $options): ?float
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $negative = str_contains($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));

        $thousands = (string) ($options['thousands'] ?? '.');
        $decimal = (string) ($options['decimal'] ?? ',');

        // Rimuove valuta, spazi, parentesi e separatore migliaia; normalizza il
        // separatore decimale a punto.
        if ($thousands !== '') {
            $s = str_replace($thousands, '', $s);
        }
        $s = str_replace($decimal, '.', $s);
        $s = preg_replace('/[^0-9.]/', '', $s) ?? '';

        if ($s === '' || $s === '.') {
            return null;
        }

        $amount = (float) $s;

        return round($negative ? -abs($amount) : $amount, 2);
    }

    /**
     * Converte una data secondo il formato dato; in fallback prova Carbon::parse.
     */
    public function parseDate(mixed $value, string $format): ?Carbon
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat($format, $s);
            if ($date !== false) {
                return $date->startOfDay();
            }
        } catch (Throwable) {
            // cade nel parse generico sotto
        }

        try {
            return Carbon::parse($s)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Legge le righe del CSV come array indicizzati per posizione.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, string>>
     */
    public function readRows(string $path, array $options): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Impossibile aprire il file CSV.');
        }

        $delimiter = (string) ($options['delimiter'] ?? $this->detectDelimiter($path));
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv su riga vuota ritorna [null]: la saltiamo.
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($v): string => (string) $v, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Indovina il separatore leggendo la prima riga: preferisce ';' (comune
     * negli export italiani), altrimenti ','.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ';';
        }
        $firstLine = (string) fgets($handle);
        fclose($handle);

        return substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param  array<int, string>  $row
     */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        return (string) ($row[$index] ?? '');
    }

    /**
     * Trova l'indice di colonna la cui intestazione combacia (case-insensitive).
     *
     * @param  array<int, string>  $header
     */
    private function findColumn(array $header, string $name): ?int
    {
        $name = mb_strtolower(trim($name));
        foreach ($header as $i => $text) {
            if (mb_strtolower(trim((string) $text)) === $name) {
                return $i;
            }
        }

        return null;
    }
}
