<?php

namespace App\Services\Ai;

use Anthropic\Client;
use RuntimeException;

/**
 * Ricava dal file stesso come va letto un estratto conto: separatore, formato
 * dei numeri e delle date, e quale intestazione corrisponde a quale campo.
 *
 * I preset di config/banks.php coprono le banche che usiamo, ma non un file
 * nuovo: una banca in più, un export cambiato, un tracciato di un
 * commercialista. In quei casi la mappatura si compila a mano leggendo l'header
 * e indovinando, ed è lì che nascono gli import storti. Qui il modello legge le
 * prime righe e propone la configurazione, che resta comunque modificabile
 * prima di importare.
 */
class BankCsvLayoutDetector
{
    /** Modalità importo ammesse dall'importatore. */
    private const MODES = ['signed', 'dare_avere'];

    /** Campi della mappatura colonne, nello stesso ordine del form. */
    private const FIELDS = [
        'booked_at', 'value_date', 'amount', 'dare', 'avere',
        'description', 'counterparty', 'reference',
    ];

    /**
     * Quante righe del file mandare al modello. L'header più una manciata di
     * movimenti bastano a capire il tracciato: mandare tutto l'estratto
     * costerebbe di più e regalerebbe al modello dati che non gli servono.
     */
    private const RIGHE = 12;

    /** Tetto di sicurezza sul testo inviato, per file con righe lunghissime. */
    private const MAX_CHARS = 6000;

    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * @return array{
     *   delimiter: string, decimal: string, thousands: string,
     *   date_format: string, amount_mode: string,
     *   columns: array<string, string>, note: ?string
     * }
     */
    public function detect(string $path): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Chiave API Anthropic non configurata (ANTHROPIC_API_KEY).');
        }

        $estratto = $this->primeRighe($path);

        if (trim($estratto) === '') {
            throw new RuntimeException('Il file sembra vuoto: non c\'è niente da riconoscere.');
        }

        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));
        $model = (string) config('services.anthropic.model', 'claude-opus-5');

        $message = $client->messages->create(
            maxTokens: 1024,
            model: $model,
            system: $this->systemPrompt(),
            messages: [[
                'role' => 'user',
                'content' => "Prime righe del file:\n\n".$estratto,
            ]],
        );

        app(AiUsageRecorder::class)->record('bank_csv_layout', $model, $message->usage);

        return $this->mappaturaDa($this->firstText($message));
    }

    /**
     * Le prime righe del file come testo grezzo. Volutamente senza parsing: è
     * proprio il separatore una delle cose da riconoscere, quindi darlo per
     * scontato qui vanificherebbe il lavoro.
     */
    private function primeRighe(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Impossibile leggere il file caricato.');
        }

        $righe = [];

        while (count($righe) < self::RIGHE && ($riga = fgets($handle)) !== false) {
            $righe[] = rtrim($riga, "\r\n");
        }

        fclose($handle);

        return mb_substr(implode("\n", $righe), 0, self::MAX_CHARS);
    }

    private function systemPrompt(): string
    {
        $campi = implode(', ', self::FIELDS);

        return <<<PROMPT
        Ti vengono date le prime righe di un estratto conto bancario esportato da una
        banca (CSV o simile, spesso italiano). Devi capire come vanno letti quei dati.

        Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo prima o dopo e
        senza blocchi markdown, con esattamente questi campi:

        - "delimiter": il carattere che separa le colonne (es. ";" oppure "," o "\\t").
        - "decimal": il separatore decimale usato negli importi ("," oppure ".").
        - "thousands": il separatore delle migliaia ("." o "," o "" se non c'è).
        - "date_format": il formato delle date in notazione PHP (es. "d/m/Y",
          "Y-m-d", "d-m-Y").
        - "amount_mode": "dare_avere" se ci sono DUE colonne separate per uscite ed
          entrate; "signed" se c'è UNA sola colonna di importo, col segno.
        - "columns": oggetto che associa a ciascun campo il NOME ESATTO
          dell'intestazione presente nel file, o stringa vuota se quel campo nel file
          non c'è. I campi sono: {$campi}.
        - "note": una frase breve in italiano su cosa hai riconosciuto (es. "Estratto
          InBank con colonne Dare/Avere"), oppure null.

        Regole importanti:
        - I nomi in "columns" devono essere copiati LETTERALMENTE dalla riga di
          intestazione del file, con le stesse maiuscole e gli stessi spazi. Non
          tradurli e non normalizzarli.
        - Se "amount_mode" è "dare_avere", compila "dare" (uscite/addebiti) e "avere"
          (entrate/accrediti) e lascia "amount" vuoto. Se è "signed", compila "amount"
          e lascia "dare" e "avere" vuoti.
        - "booked_at" è la data contabile dell'operazione, "value_date" la data valuta.
          Se il file ha una sola data, mettila in "booked_at" e lascia "value_date"
          vuoto.
        - "description" è il testo che descrive il movimento. "counterparty" è la
          controparte, se c'è una colonna dedicata. "reference" è un identificativo
          univoco del movimento, se presente.
        - Se un campo non esiste nel file, stringa vuota. Non inventare intestazioni.
        PROMPT;
    }

    private function firstText(mixed $message): string
    {
        foreach ($message->content as $block) {
            $type = is_object($block) ? ($block->type ?? null) : ($block['type'] ?? null);
            if ($type === 'text') {
                return is_object($block) ? (string) $block->text : (string) $block['text'];
            }
        }

        throw new RuntimeException('Risposta del modello priva di testo.');
    }

    /**
     * La configurazione descritta dalla risposta del modello, normalizzata e
     * resa coerente con quello che l'importatore sa leggere.
     *
     * Pubblica perché è qui che sta il lavoro vero — e perché la chiamata di
     * rete sopra non è simulabile, mentre questa lo è.
     *
     * @return array{
     *   delimiter: string, decimal: string, thousands: string,
     *   date_format: string, amount_mode: string,
     *   columns: array<string, string>, note: ?string
     * }
     */
    public function mappaturaDa(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException('Riconoscimento non riuscito: risposta non in JSON.');
        }

        $mode = (string) ($data['amount_mode'] ?? 'signed');

        if (! in_array($mode, self::MODES, true)) {
            $mode = 'signed';
        }

        $columns = is_array($data['columns'] ?? null) ? $data['columns'] : [];
        $mappate = [];

        foreach (self::FIELDS as $campo) {
            $mappate[$campo] = trim((string) ($columns[$campo] ?? ''));
        }

        // Coerenza fra modalità e colonne: il modello a volte compila entrambe
        // le forme. L'importatore userebbe solo quella giusta, ma lasciare
        // valori spuri nel form fa sembrare sbagliata una configurazione buona.
        if ($mode === 'dare_avere') {
            $mappate['amount'] = '';
        } else {
            $mappate['dare'] = '';
            $mappate['avere'] = '';
        }

        if ($mappate['booked_at'] === '') {
            throw new RuntimeException('Non sono riuscito a riconoscere la colonna della data: compila la mappatura a mano.');
        }

        $note = $data['note'] ?? null;

        return [
            'delimiter' => $this->carattere($data['delimiter'] ?? ';', ';'),
            'decimal' => $this->carattere($data['decimal'] ?? ',', ','),
            'thousands' => (string) ($data['thousands'] ?? ''),
            'date_format' => trim((string) ($data['date_format'] ?? '')) ?: 'd/m/Y',
            'amount_mode' => $mode,
            'columns' => $mappate,
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
        ];
    }

    /**
     * Un separatore è un carattere solo. Il modello a volte lo descrive
     * ("punto e virgola") o lo manda scappato: meglio ricadere sul default che
     * scrivere nel form una stringa che l'importatore non capirebbe.
     */
    private function carattere(mixed $valore, string $default): string
    {
        $valore = (string) $valore;

        $valore = match ($valore) {
            '\\t' => "\t",
            '\\n' => "\n",
            default => $valore,
        };

        return mb_strlen($valore) === 1 ? $valore : $default;
    }
}
