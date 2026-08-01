<?php

namespace App\Services\Ai;

use Anthropic\Client;
use RuntimeException;

/**
 * Estrae i dati di una fattura EMESSA (PDF) usando Claude.
 *
 * Serve per lo storico dei clienti fatturati fuori da Fatture in Cloud (es.
 * Fiscozen, che non ha API): si caricano le copie di cortesia in PDF, il modello
 * ne ricava cliente, numero, data e righe, e l'utente rivede tutto prima di
 * creare le fatture in TrackFlow.
 *
 * Speculare a ForeignInvoiceExtractor, che fa lo stesso per le passive estere.
 */
class IssuedInvoiceExtractor
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * @return array{
     *   client_name: string, client_vat: ?string, client_tax_code: ?string,
     *   number: ?string, document_date: ?string, currency: string,
     *   vat_rate: float, total: float, is_credit_note: bool,
     *   lines: array<int, array{name: string, qty: float, net_price: float}>
     * }
     */
    public function extract(string $pdfBinary): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Chiave API Anthropic non configurata (ANTHROPIC_API_KEY).');
        }

        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));
        $model = (string) config('services.anthropic.model', 'claude-opus-4-8');

        $message = $client->messages->create(
            maxTokens: 2048,
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
                    ['type' => 'text', 'text' => 'Estrai i dati di questa fattura emessa e rispondi SOLO con il JSON richiesto.'],
                ],
            ]],
        );

        app(AiUsageRecorder::class)->record('issued_invoice', $model, $message->usage);

        return $this->parse($this->firstText($message));
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        Sei un assistente contabile. Ti viene fornita una fattura EMESSA in PDF (spesso
        una "copia di cortesia"). Chi emette la fattura è Giorgio Giotto / G8Labs: NON è
        il cliente. Il cliente è il DESTINATARIO del documento.

        Estrai i dati e rispondi ESCLUSIVAMENTE con un oggetto JSON valido (nessun testo
        prima o dopo, nessun blocco markdown), con esattamente questi campi:

        - "client_name": ragione sociale del DESTINATARIO (il cliente), stringa.
        - "client_vat": partita IVA del destinatario, stringa oppure null.
        - "client_tax_code": codice fiscale del destinatario, stringa oppure null.
        - "number": numero della fattura come appare sul documento, stringa oppure null.
          Tieni il formato originale, comprensivo di anno o serie (es. "23/2026").
        - "document_date": data della fattura in formato "YYYY-MM-DD", oppure null.
        - "currency": codice valuta ISO (es. "EUR").
        - "vat_rate": aliquota IVA applicata come numero (es. 22). In regime
          forfettario / operazione in franchigia IVA vale 0.
        - "total": totale del documento (numero, punto come separatore decimale).
        - "is_credit_note": true se è una NOTA DI CREDITO, false se è una fattura
          normale. Gli importi restano positivi anche per le note di credito.
        - "lines": array delle righe della fattura, una per riga della tabella, con:
            - "name": descrizione della riga, stringa.
            - "qty": quantità come numero (se assente usa 1).
            - "net_price": importo UNITARIO come numero (la colonna "Importo unit.").
              Se il documento riporta solo l'importo totale di riga, metti quello e
              usa qty 1.

        Attenzione: qty × net_price deve dare l'importo della riga. Non invertire
        quantità e prezzo unitario. Gli importi devono essere numeri, non stringhe, e
        senza simbolo di valuta né separatore delle migliaia.
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
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new RuntimeException('Estrazione non riuscita: risposta non in JSON.');
        }

        return [
            'client_name' => trim((string) ($data['client_name'] ?? '')),
            'client_vat' => $this->stringOrNull($data['client_vat'] ?? null),
            'client_tax_code' => $this->stringOrNull($data['client_tax_code'] ?? null),
            'number' => $this->stringOrNull($data['number'] ?? null),
            'document_date' => $this->stringOrNull($data['document_date'] ?? null),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'EUR'))) ?: 'EUR',
            'vat_rate' => round((float) ($data['vat_rate'] ?? 0), 2),
            'total' => round((float) ($data['total'] ?? 0), 2),
            'is_credit_note' => (bool) ($data['is_credit_note'] ?? false),
            'lines' => $this->parseLines($data['lines'] ?? []),
        ];
    }

    /**
     * @return array<int, array{name: string, qty: float, net_price: float}>
     */
    private function parseLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        return collect($lines)
            ->filter(fn ($line): bool => is_array($line) && filled($line['name'] ?? null))
            ->map(fn (array $line): array => [
                'name' => trim((string) $line['name']),
                'qty' => round((float) ($line['qty'] ?? 1) ?: 1, 2),
                'net_price' => round((float) ($line['net_price'] ?? 0), 2),
            ])
            ->values()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
