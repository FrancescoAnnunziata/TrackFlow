<?php

namespace App\Services\Reimbursements;

use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Legge una nota spese chilometrica nel formato Excel «RIMB. <Mese> <Anno>»:
 * foglio «rimborso» con intestazione (chi, mese, tariffa €/km), una riga per
 * giorno (km e altre spese) e i totali in fondo.
 *
 * Il file è un xlsx qualsiasi (zip di XML): lo si legge con ZipArchive invece
 * di tirarsi dietro una libreria di fogli di calcolo per un solo modulo.
 *
 * Ogni incoerenza è un errore, non un'ipotesi: un mese sbagliato o un totale che
 * non torna finirebbero in contabilità come rimborso vero.
 */
class NotaSpeseExcel
{
    private const MESI = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];

    /**
     * @param  array<int, array{giorno: int, descrizione: string, importo: float}>  $altreSpese
     */
    public function __construct(
        public readonly string $persona,
        public readonly Carbon $mese,
        public readonly float $tariffaKm,
        public readonly float $km,
        public readonly array $altreSpese,
    ) {}

    /**
     * @param  string  $path  file sul disco
     * @param  string  $nomeFile  nome originale, per confrontare il mese
     *
     * @throws RuntimeException con un messaggio da mostrare a chi importa
     */
    public static function leggi(string $path, string $nomeFile): self
    {
        $righe = self::righeDelFoglio($path, 'rimborso');

        $persona = null;
        $mese = null;
        $tariffa = null;
        $totaleDichiarato = null;
        $km = 0.0;
        $altre = [];
        $inTabella = false;

        foreach ($righe as $celle) {
            $a = trim((string) ($celle['A'] ?? ''));
            $c = trim((string) ($celle['C'] ?? ''));

            if (preg_match('/^IL SIG\.?\s+(.+)$/iu', $a, $m)) {
                $persona = trim($m[1]);
            } elseif (preg_match('/MESE DI\s+(\p{L}+)\s+(\d{4})/iu', $a, $m)) {
                $mese = self::mese($m[1], (int) $m[2]);
            } elseif (strtoupper($a) === 'AUTOVETTURA') {
                $tariffa = (float) ($celle['E'] ?? 0);
            } elseif (strtoupper($a) === 'GG') {
                $inTabella = true;
            } elseif (strtoupper($c) === 'TOTALE TRASFERTE') {
                $totaleDichiarato = (float) ($celle['E'] ?? 0);
                $inTabella = false;
            } elseif ($inTabella && is_numeric($a) && (int) $a >= 1 && (int) $a <= 31) {
                $km += (float) ($celle['E'] ?? 0);

                $importo = (float) ($celle['F'] ?? 0);
                if ($importo > 0) {
                    $descrizione = trim((string) ($celle['G'] ?? '')) ?: trim((string) ($celle['D'] ?? '')) ?: 'Altre spese';
                    $altre[] = ['giorno' => (int) $a, 'descrizione' => $descrizione, 'importo' => round($importo, 2)];
                }
            }
        }

        if ($persona === null || $mese === null || $tariffa === null || $totaleDichiarato === null) {
            throw new RuntimeException('non sembra una nota spese chilometrica: mancano intestazione, mese, tariffa o totale.');
        }

        // Il modello si copia di mese in mese: se l'intestazione non è stata
        // aggiornata, nome del file e contenuto dicono due mesi diversi.
        $meseDalNome = self::meseDalNomeFile($nomeFile);
        if ($meseDalNome !== null && ! $meseDalNome->isSameMonth($mese)) {
            throw new RuntimeException(sprintf(
                'il nome del file dice %s ma l\'intestazione dice «%s»: correggi l\'intestazione del foglio e ricaricalo.',
                self::etichetta($meseDalNome), self::etichetta($mese),
            ));
        }

        $nota = new self($persona, $mese, $tariffa, $km, $altre);

        if (abs($nota->totale() - round($totaleDichiarato, 2)) > 0.01) {
            throw new RuntimeException(sprintf(
                'il totale del foglio (€ %s) non torna con km × tariffa + altre spese (€ %s).',
                number_format($totaleDichiarato, 2, ',', '.'), number_format($nota->totale(), 2, ',', '.'),
            ));
        }

        return $nota;
    }

    public function importoKm(): float
    {
        return round($this->km * $this->tariffaKm, 2);
    }

    /**
     * Totale come somma delle voci arrotondate: è l'importo del rimborso, e
     * deve coincidere con la somma dei costi che lo compongono.
     */
    public function totale(): float
    {
        return round($this->importoKm() + array_sum(array_column($this->altreSpese, 'importo')), 2);
    }

    public function etichettaMese(): string
    {
        return self::etichetta($this->mese);
    }

    public static function etichetta(Carbon $mese): string
    {
        return ucfirst((string) array_search($mese->month, self::MESI, true)).' '.$mese->year;
    }

    private static function mese(string $nome, int $anno): ?Carbon
    {
        $numero = self::MESI[mb_strtolower($nome)] ?? null;

        return $numero === null ? null : Carbon::create($anno, $numero, 1)->startOfDay();
    }

    /**
     * «RIMB. Ago 2026.xlsx» → agosto 2026. Le prime tre lettere bastano.
     */
    private static function meseDalNomeFile(string $nomeFile): ?Carbon
    {
        if (! preg_match('/\b(\p{L}{3,})\.?\s+(\d{4})\b/u', $nomeFile, $m)) {
            return null;
        }

        $prefisso = mb_strtolower(mb_substr($m[1], 0, 3));
        foreach (self::MESI as $nome => $numero) {
            if (str_starts_with($nome, $prefisso)) {
                return Carbon::create((int) $m[2], $numero, 1)->startOfDay();
            }
        }

        return null;
    }

    /**
     * Righe del foglio come [numero riga => [colonna => valore]], con le
     * stringhe condivise già risolte e i valori calcolati delle formule.
     *
     * @return array<int, array<string, string>>
     */
    private static function righeDelFoglio(string $path, string $nomeFoglio): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('non è un file Excel (.xlsx) leggibile.');
        }

        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $relazioni = self::xml($zip, 'xl/_rels/workbook.xml.rels');

            $rId = null;
            foreach ($workbook->sheets->sheet as $foglio) {
                if (mb_strtolower((string) $foglio['name']) === $nomeFoglio) {
                    $rId = (string) $foglio->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                }
            }
            if ($rId === null) {
                throw new RuntimeException("manca il foglio «{$nomeFoglio}».");
            }

            $target = null;
            foreach ($relazioni->Relationship as $rel) {
                if ((string) $rel['Id'] === $rId) {
                    $target = ltrim((string) $rel['Target'], '/');
                }
            }
            $target = str_starts_with((string) $target, 'xl/') ? $target : 'xl/'.$target;

            $condivise = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach (self::xml($zip, 'xl/sharedStrings.xml')->si as $si) {
                    // Il testo formattato è spezzato in più <r><t>: si ricompone.
                    $condivise[] = implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
                }
            }

            $righe = [];
            foreach (self::xml($zip, $target)->sheetData->row as $riga) {
                foreach ($riga->c as $cella) {
                    if (! preg_match('/^([A-Z]+)(\d+)$/', (string) $cella['r'], $m)) {
                        continue;
                    }
                    $valore = match ((string) $cella['t']) {
                        's' => $condivise[(int) $cella->v] ?? '',
                        'inlineStr' => (string) $cella->is->t,
                        default => (string) $cella->v,
                    };
                    if ($valore !== '') {
                        $righe[(int) $m[2]][$m[1]] = $valore;
                    }
                }
            }
            ksort($righe);

            return $righe;
        } finally {
            $zip->close();
        }
    }

    private static function xml(ZipArchive $zip, string $nome): SimpleXMLElement
    {
        $contenuto = $zip->getFromName($nome);
        $xml = $contenuto === false ? false : simplexml_load_string($contenuto, options: LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('il file Excel è danneggiato o in un formato non previsto.');
        }

        return $xml;
    }
}
