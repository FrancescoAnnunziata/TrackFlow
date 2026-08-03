<?php

namespace App\Support;

/**
 * Un'intestazione fra quelle configurate (vedi config/azienda.php): chi emette
 * il documento. Si sceglie sul preventivo; di ogni intestazione compare solo
 * ciò che è compilato, così il documento resta pulito anche con pochi dati.
 */
class Emittente
{
    /**
     * @param  array<string, mixed>  $dati
     */
    private function __construct(
        public readonly string $chiave,
        private readonly array $dati,
    ) {}

    /**
     * L'intestazione indicata, o quella di default se la chiave è vuota o non
     * esiste più in configurazione (documenti vecchi, chiavi rinominate).
     */
    public static function make(?string $chiave = null): self
    {
        $tutte = self::tutte();
        $scelta = $chiave !== null && isset($tutte[$chiave])
            ? $chiave
            : self::chiaveDefault();

        return new self($scelta, $tutte[$scelta] ?? []);
    }

    public static function chiaveDefault(): string
    {
        $default = (string) config('azienda.default');
        $tutte = self::tutte();

        return isset($tutte[$default]) ? $default : (string) array_key_first($tutte);
    }

    /**
     * Opzioni per la select del preventivo: chiave => nome esteso.
     *
     * @return array<string, string>
     */
    public static function opzioni(): array
    {
        return array_map(
            fn (array $dati): string => (string) ($dati['nome'] ?? ''),
            self::tutte(),
        );
    }

    /**
     * Nome dell'intestazione indicata, per tabelle e infolist.
     */
    public static function nomeDi(?string $chiave): string
    {
        return self::make($chiave)->nome();
    }

    public function nome(): string
    {
        return $this->campo('nome') ?? (string) config('app.name');
    }

    public function sottotitolo(): ?string
    {
        return $this->campo('sottotitolo');
    }

    /**
     * Via, CAP città (PROV) su una riga, saltando i pezzi mancanti.
     */
    public function indirizzo(): ?string
    {
        $citta = trim(implode(' ', array_filter([
            $this->campo('cap'),
            $this->campo('citta'),
            ($p = $this->campo('provincia')) ? "({$p})" : null,
        ])));

        $righe = array_filter([$this->campo('indirizzo'), $citta ?: null]);

        return $righe ? implode(' — ', $righe) : null;
    }

    /**
     * Righe identificative (P.IVA, C.F., contatti) già formattate.
     *
     * @return array<int, string>
     */
    public function righeIdentificative(): array
    {
        $righe = [];

        if ($piva = $this->campo('partita_iva')) {
            $righe[] = "P.IVA {$piva}";
        }

        // Il C.F. si stampa solo se diverso dalla P.IVA (per le ditte individuali
        // coincidono e ripeterlo è rumore).
        $cf = $this->campo('codice_fiscale');
        if ($cf && $cf !== $piva) {
            $righe[] = "C.F. {$cf}";
        }

        foreach (['email', 'telefono', 'pec'] as $chiave) {
            if ($valore = $this->campo($chiave)) {
                $righe[] = $valore;
            }
        }

        return $righe;
    }

    public function iban(): ?string
    {
        return $this->campo('iban');
    }

    public function notaFiscale(): ?string
    {
        return $this->campo('nota_fiscale');
    }

    /**
     * Condizioni generali, salvo quelle specifiche dell'intestazione.
     *
     * @return array<int, string>
     */
    public function condizioni(): array
    {
        $condizioni = $this->dati['condizioni'] ?? config('azienda.condizioni', []);

        return array_values(array_filter((array) $condizioni));
    }

    /**
     * Logo come data URI: unico modo per farlo comparire identico nella pagina
     * web e dentro il PDF, senza dipendere da come dompdf risolve gli URL.
     */
    public function logoDataUri(): ?string
    {
        $relativo = $this->campo('logo');

        if (! $relativo) {
            return null;
        }

        $percorso = public_path(ltrim($relativo, '/'));

        if (! is_file($percorso)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($percorso, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return "data:{$mime};base64,".base64_encode((string) file_get_contents($percorso));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function tutte(): array
    {
        return (array) config('azienda.emittenti', []);
    }

    private function campo(string $chiave): ?string
    {
        $valore = $this->dati[$chiave] ?? null;

        return filled($valore) ? trim((string) $valore) : null;
    }
}
