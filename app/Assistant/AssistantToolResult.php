<?php

namespace App\Assistant;

/**
 * Esito dell'esecuzione di un tool: `content` torna al modello; `summary` è la
 * riga mostrata nella UI (chip); `action` è una proposta che l'utente deve
 * confermare prima che venga scritta a DB (es. una riconciliazione).
 *
 * `blocks`, se presente, sostituisce `content` verso il modello: serve a
 * passargli un file vero (immagine o PDF) invece della sola descrizione.
 * `content` resta il riassunto testuale, per i log e per i test.
 */
class AssistantToolResult
{
    /**
     * @param  array<string, mixed>|null  $action
     * @param  array<int, array<string, mixed>>|null  $blocks
     */
    public function __construct(
        public readonly string $content,
        public readonly string $summary = '',
        public readonly bool $isError = false,
        public readonly ?array $action = null,
        public readonly ?array $blocks = null,
    ) {}

    /**
     * Contenuto per il tool_result dell'API: i blocchi se ci sono, altrimenti
     * il testo.
     *
     * @return string|array<int, array<string, mixed>>
     */
    public function forModel(): string|array
    {
        return $this->blocks ?? $this->content;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks  blocchi text/image/document dell'API Messages
     */
    public static function withBlocks(string $content, string $summary, array $blocks): self
    {
        return new self($content, $summary, false, null, $blocks);
    }

    public static function ok(string $content, string $summary = ''): self
    {
        return new self($content, $summary);
    }

    public static function error(string $message): self
    {
        return new self($message, $message, true);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function proposal(string $content, string $summary, array $action): self
    {
        return new self($content, $summary, false, $action);
    }
}
