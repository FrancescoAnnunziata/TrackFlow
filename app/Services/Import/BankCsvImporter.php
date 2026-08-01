<?php

namespace App\Services\Import;

use App\Models\BankTransaction;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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
     * Righe di riepilogo che alcuni estratti (InBank) mettono in coda: NON sono
     * movimenti ma saldi/disponibilità. Match per prefisso esatto della
     * descrizione, così un bonifico con causale "Saldo Fattura…" resta importato.
     *
     * @var array<int, string>
     */
    private const SUMMARY_DESCRIPTION_PREFIXES = [
        'saldo contabile',
        'saldo liquido',
        'saldo sbf',
        'saldo iniziale',
        'saldo finale',
        'disponibilità al',
        'disponibilita al',
    ];

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

        // Alcuni export (es. Directa) antepongono righe di intestazione del
        // documento prima della vera riga di header: scartiamo tutto ciò che
        // precede la riga che contiene il nome della colonna Data.
        $rows = $this->stripPreamble($rows, (string) ($columns['booked_at'] ?? ''));
        if (count($rows) < 2) {
            return ['imported' => 0, 'skipped' => 0, 'duplicates' => 0];
        }

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

        // Alcune carte (Vivid) esportano due righe per pagamento: l'autorizzazione
        // (descrizione vuota) e l'addebito col nome del merchant, a 1-2 giorni.
        // Il dedup content-based non le unisce (descrizioni diverse), quindi
        // saltiamo la riga di autorizzazione quando esiste un addebito descritto
        // dello stesso importo entro pochi giorni.
        $describedDates = $this->describedDateMap($rows, $idx, $mode, $options);

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

            // Righe di riepilogo (saldo/disponibilità): non sono movimenti.
            if ($this->isSummaryRow($description)) {
                $skipped++;

                continue;
            }

            // Riga di autorizzazione (descrizione vuota) con addebito gemello descritto.
            if ($description === '' && $this->hasDescribedTwin($describedDates, $amount, $bookedAt)) {
                $skipped++;

                continue;
            }

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
     * Mappa importo → date delle righe CON descrizione (gli addebiti veri), usata
     * per riconoscere le righe di autorizzazione a descrizione vuota da saltare.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $idx
     * @param  array<string, mixed>  $options
     * @return array<string, array<int, string>>
     */
    private function describedDateMap(array $rows, array $idx, string $mode, array $options): array
    {
        $map = [];
        foreach ($rows as $row) {
            $desc = trim((string) $this->cell($row, $idx['description'] ?? null));
            if ($desc === '' || $this->isSummaryRow($desc)) {
                continue;
            }
            $amount = $this->resolveAmount($row, $idx, $mode, $options);
            $date = $this->parseDate($this->cell($row, $idx['booked_at'] ?? null), (string) ($options['date_format'] ?? 'd/m/Y'));
            if ($amount === null || $date === null) {
                continue;
            }
            $map[number_format($amount, 2, '.', '')][] = $date->format('Y-m-d');
        }

        return $map;
    }

    /**
     * True se esiste un addebito descritto dello stesso importo entro ±2 giorni:
     * la riga vuota è la sua autorizzazione (duplicato da saltare).
     *
     * @param  array<string, array<int, string>>  $describedDates
     */
    private function hasDescribedTwin(array $describedDates, float $amount, Carbon $bookedAt): bool
    {
        foreach ($describedDates[number_format($amount, 2, '.', '')] ?? [] as $date) {
            if (abs(Carbon::parse($date)->diffInDays($bookedAt)) <= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * True se la descrizione è una riga di riepilogo (saldo/disponibilità), da
     * non importare come movimento.
     */
    private function isSummaryRow(string $description): bool
    {
        $desc = mb_strtolower(trim($description));
        if ($desc === '') {
            return false;
        }

        foreach (self::SUMMARY_DESCRIPTION_PREFIXES as $prefix) {
            if (str_starts_with($desc, $prefix)) {
                return true;
            }
        }

        return false;
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

        // Il formato configurato, poi lo stesso con gli altri separatori: Vivid
        // esporta 02.06.2026 o 02-06-2026 a seconda del tipo di estratto, e non
        // vogliamo costringere a ritoccare il preset ogni volta.
        foreach ($this->dateFormatVariants($format) as $candidate) {
            try {
                $date = Carbon::createFromFormat($candidate, $s);
                if ($date !== false) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                // prova la variante successiva
            }
        }

        try {
            return Carbon::parse($s)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Il formato dato più le sue varianti con separatore diverso (/, -, .), in
     * ordine: prima quello configurato, poi gli altri.
     *
     * @return array<int, string>
     */
    private function dateFormatVariants(string $format): array
    {
        $separators = ['/', '-', '.'];
        $used = null;

        foreach ($separators as $separator) {
            if (str_contains($format, $separator)) {
                $used = $separator;

                break;
            }
        }

        if ($used === null) {
            return [$format];
        }

        return collect([$used, ...$separators])
            ->unique()
            ->map(fn (string $separator): string => str_replace($used, $separator, $format))
            ->values()
            ->all();
    }

    /**
     * Legge le righe del file (CSV o foglio XLSX/ODS) come array indicizzati per
     * posizione. Il formato è dedotto dall'estensione.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, string>>
     */
    public function readRows(string $path, array $options): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'ods'], true)) {
            return $this->readSpreadsheet($path, $ext);
        }

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
     * Legge il primo foglio di un file XLSX/ODS come righe di stringhe. Date e
     * numeri sono normalizzati a stringa (le date a Y-m-d), così passano per lo
     * stesso parsing dei CSV.
     *
     * @return array<int, array<int, string>>
     */
    private function readSpreadsheet(string $path, string $ext): array
    {
        $reader = $ext === 'ods' ? new OdsReader : new XlsxReader;
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format('Y-m-d');
                    }
                    $cells[] = is_scalar($value) ? (string) $value : '';
                }
                $rows[] = $cells;
            }

            break; // solo il primo foglio
        }

        $reader->close();

        return $rows;
    }

    /**
     * Scarta le righe di preambolo che precedono l'intestazione: ritorna le
     * righe a partire dalla prima che contiene, in una cella, il nome della
     * colonna Data. Se non la trova (header già in prima riga), ritorna invariato.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<int, string>>
     */
    private function stripPreamble(array $rows, string $bookedAtHeader): array
    {
        $needles = $this->headerAliases($bookedAtHeader);
        if ($needles === []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            foreach ($row as $cell) {
                if (in_array(mb_strtolower(trim((string) $cell)), $needles, true)) {
                    return array_values(array_slice($rows, $i));
                }
            }
        }

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
     * Il nome può elencare più alternative separate da "|": serve alle banche che
     * cambiano intestazione fra un tipo di export e l'altro (es. Vivid, che usa
     * "Transaction date" o "Completed date"). Vince la prima che esiste nel file.
     *
     * @param  array<int, string>  $header
     */
    private function findColumn(array $header, string $name): ?int
    {
        foreach ($this->headerAliases($name) as $alias) {
            foreach ($header as $i => $text) {
                if (mb_strtolower(trim((string) $text)) === $alias) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Nomi di intestazione accettati per un campo, normalizzati.
     *
     * @return array<int, string>
     */
    private function headerAliases(string $name): array
    {
        return collect(explode('|', $name))
            ->map(fn (string $alias): string => mb_strtolower(trim($alias)))
            ->filter(fn (string $alias): bool => $alias !== '')
            ->values()
            ->all();
    }
}
