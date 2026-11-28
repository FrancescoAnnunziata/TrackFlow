<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chi emette i documenti: G8 Labs S.r.l. (principale) e la P.IVA personale di
 * Giorgio. Clienti, fatture, preventivi, conti e fatture passive appartengono a
 * un emittente; dashboard, soglia del forfettario e riconciliazione separano i
 * due mondi in base a questo, non allo strumento con cui si fattura.
 *
 * Di ogni intestazione compare sul documento solo ciò che è compilato.
 */
class Emittente extends Model
{
    protected $table = 'emittenti';

    public const CANALE_FIC = 'fatture_in_cloud';

    public const CANALE_FISCOZEN = 'fiscozen';

    public const CANALE_ACUBE = 'acube';

    /** Fattura emessa fuori da TrackFlow e registrata a mano. */
    public const CANALE_MANUALE = 'manuale';

    public const REGIME_ORDINARIO = 'RF01';

    public const REGIME_FORFETTARIO = 'RF19';

    protected $fillable = [
        'chiave',
        'nome',
        'sottotitolo',
        'partita_iva',
        'codice_fiscale',
        'indirizzo',
        'cap',
        'citta',
        'provincia',
        'email',
        'telefono',
        'pec',
        'iban',
        'logo',
        'nota_fiscale',
        'canale_invio',
        'regime_fiscale',
        'principale',
    ];

    protected $casts = [
        'principale' => 'boolean',
    ];

    /**
     * La società. Esiste sempre: la crea la migrazione.
     */
    public static function principale(): self
    {
        return static::query()->where('principale', true)->orderBy('id')->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    public static function canali(): array
    {
        return [
            self::CANALE_FIC => 'Fatture in Cloud',
            self::CANALE_ACUBE => 'A-Cube',
            self::CANALE_FISCOZEN => 'Fiscozen',
            self::CANALE_MANUALE => 'A mano, fuori da TrackFlow',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function regimi(): array
    {
        return [
            self::REGIME_ORDINARIO => 'Ordinario (RF01)',
            self::REGIME_FORFETTARIO => 'Forfettario (RF19)',
        ];
    }

    /**
     * Emittente a cui appartiene un movimento o un documento. Costi, spese e
     * rimborsi non hanno un emittente proprio: sono della società.
     */
    public static function idDi(Model $model): int
    {
        if ($model instanceof BankTransaction) {
            $model->loadMissing('bankAccount');

            return (int) ($model->bankAccount?->emittente_id ?? self::principale()->id);
        }

        return (int) ($model->getAttribute('emittente_id') ?? self::principale()->id);
    }

    public static function canaleLabel(?string $canale): string
    {
        return self::canali()[$canale] ?? (string) $canale;
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function regimiAnnuali(): HasMany
    {
        return $this->hasMany(EmittenteRegime::class);
    }

    /**
     * Regime fiscale dell'anno: l'eccezione registrata per quell'anno, se c'è,
     * altrimenti quello abituale.
     */
    public function regimeNel(int $anno): string
    {
        return $this->regimiAnnuali->firstWhere('anno', $anno)?->regime_fiscale
            ?? $this->regime_fiscale;
    }

    public function isForfettarioNel(int $anno): bool
    {
        return $this->regimeNel($anno) === self::REGIME_FORFETTARIO;
    }

    /**
     * True se le fatture di questo emittente partono da TrackFlow verso
     * Fatture in Cloud (pulsante «Invia a Fatture in Cloud»).
     */
    public function inviaConFic(): bool
    {
        return $this->canale_invio === self::CANALE_FIC;
    }

    /**
     * True se TrackFlow ha almeno un conto di questo emittente: solo allora le
     * sue fatture si possono riconciliare con i movimenti.
     */
    public function haConti(): bool
    {
        return BankAccount::where('emittente_id', $this->id)->exists();
    }

    public function sottotitolo(): ?string
    {
        return $this->campo('sottotitolo');
    }

    /**
     * Via, CAP città (PROV) su una riga, saltando i pezzi mancanti.
     */
    public function indirizzoCompleto(): ?string
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

    public function notaFiscale(): ?string
    {
        return $this->campo('nota_fiscale');
    }

    /**
     * Condizioni generali stampate sul preventivo (config/azienda.php).
     *
     * @return array<int, string>
     */
    public function condizioni(): array
    {
        return array_values(array_filter((array) config('azienda.condizioni', [])));
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

    private function campo(string $chiave): ?string
    {
        $valore = $this->getAttribute($chiave);

        return filled($valore) ? trim((string) $valore) : null;
    }
}
