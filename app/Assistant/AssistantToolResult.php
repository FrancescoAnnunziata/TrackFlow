<?php

namespace App\Assistant;

/**
 * Esito dell'esecuzione di un tool: `content` torna al modello; `summary` è la
 * riga mostrata nella UI (chip); `action` è una proposta che l'utente deve
 * confermare prima che venga scritta a DB (es. una riconciliazione).
 */
class AssistantToolResult
{
    /**
     * @param  array<string, mixed>|null  $action
     */
    public function __construct(
        public readonly string $content,
        public readonly string $summary = '',
        public readonly bool $isError = false,
        public readonly ?array $action = null,
    ) {}

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
