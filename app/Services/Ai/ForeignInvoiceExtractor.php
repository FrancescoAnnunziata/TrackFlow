<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Messages\Message;
use RuntimeException;

/**
 * Estrae i dati di una fattura estera (PDF) usando Claude. Le fatture estere
 * (SiteGround, Meilisearch, Microsoft, ...) non arrivano via SdI/Fatture in
 * Cloud e vanno caricate a mano: il modello legge il PDF e ne ricava fornitore,
 * numero, data, importi e conto suggerito, che poi l'utente rivede prima di
 * creare la fattura passiva.
 */
class ForeignInvoiceExtractor
{
    /** Conti (categorie di Fatture in Cloud) fra cui il modello sceglie il suggerimento. */
    private const CONTI = [
        'Software e abbonamenti cloud',
        'Acquisto materiale e macchinari',
        'Consulenze Professionali',
        'Marketing e Pubblicità',
        'Trasferte',
        'Ristorazione',
        'Collaboratori',
    ];

    /** @return array<int, string> i conti fra cui scegliere (per la UI e il prompt). */
    public static function conti(): array
    {
        return self::CONTI;
    }

    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * @return array{
     *   supplier_name: string, supplier_vat: ?string, number: ?string,
     *   document_date: ?string, amount_net: float, amount_vat: float,
     *   amount_gross: float, currency: string, category: ?string, description: ?string,
     *   is_credit_note: bool
     * }
     */
    public function extract(string $pdfBinary): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Chiave API Anthropic non configurata (ANTHROPIC_API_KEY).');
        }

        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));
        $model = (string) config('services.anthropic.model', 'claude-opus-5');

        $message = $client->messages->create(
            maxTokens: 1024,
            model: $model,
            system: $this->systemPrompt(),
            messages: [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode($pdfBinary),
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Estrai i dati di questa fattura e rispondi SOLO con il JSON richiesto.'],
                ],
            ]],
        );

        app(AiUsageRecorder::class)->record('foreign_invoice', $model, $message->usage);

        return $this->parse($this->firstText($message));
    }

    private function systemPrompt(): string
    {
        $conti = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', self::CONTI));

        return <<<PROMPT
        Sei un assistente contabile. Ti viene fornita una fattura passiva estera in PDF.
        Estrai i dati e rispondi ESCLUSIVAMENTE con un oggetto JSON valido (nessun testo
        prima o dopo, nessun blocco markdown), con esattamente questi campi:

        - "supplier_name": ragione sociale del FORNITORE (chi emette la fattura), stringa.
        - "supplier_vat": partita IVA / VAT del fornitore, stringa oppure null.
        - "number": numero della fattura, stringa oppure null.
        - "document_date": data della fattura in formato "YYYY-MM-DD", oppure null.
        - "amount_net": imponibile (numero, punto come separatore decimale).
        - "amount_vat": IVA (numero; per reverse charge/estere spesso 0).
        - "amount_gross": totale (numero).
        - "currency": codice valuta ISO (es. "EUR", "USD").
        - "category": il conto contabile più adatto, scelto SOLO fra: {$conti}. Usa
          "Software e abbonamenti cloud" per hosting, domini, SaaS, licenze software.
        - "description": breve descrizione di cosa è stato acquistato, stringa.
        - "is_credit_note": true se il documento è una NOTA DI CREDITO (credit note,
          storno, rimborso di una fattura), false se è una normale fattura. Gli
          importi vanno comunque riportati POSITIVI anche per le note di credito.

        Il fornitore è chi EMETTE la fattura, non il cliente (G8LABS / Giorgio Giotto è
        il cliente, non il fornitore). Gli importi devono essere numeri, non stringhe.
        PROMPT;
    }

    /**
     * @param  Message  $message
     */
    private function firstText($message): string
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
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        $text = trim($text);
        // Toglie eventuali recinti markdown ```json ... ```
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        // Isola l'oggetto JSON anche se il modello aggiunge testo attorno.
        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new RuntimeException('Estrazione non riuscita: risposta non in JSON.');
        }

        return [
            'supplier_name' => trim((string) ($data['supplier_name'] ?? '')),
            'supplier_vat' => $this->stringOrNull($data['supplier_vat'] ?? null),
            'number' => $this->stringOrNull($data['number'] ?? null),
            'document_date' => $this->stringOrNull($data['document_date'] ?? null),
            'amount_net' => round((float) ($data['amount_net'] ?? 0), 2),
            'amount_vat' => round((float) ($data['amount_vat'] ?? 0), 2),
            'amount_gross' => round((float) ($data['amount_gross'] ?? 0), 2),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'EUR'))) ?: 'EUR',
            'category' => $this->stringOrNull($data['category'] ?? null),
            'description' => $this->stringOrNull($data['description'] ?? null),
            'is_credit_note' => (bool) ($data['is_credit_note'] ?? false),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
