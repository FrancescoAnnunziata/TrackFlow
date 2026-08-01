<?php

namespace App\Assistant;

/**
 * Esito di un turno del modello: il testo, i blocchi da rieccheggiare (per
 * continuare la conversazione) e le eventuali richieste di tool.
 */
class AssistantTurn
{
    /**
     * @param  array<int, array<string, mixed>>  $assistantContent  blocchi content da rimandare come turno assistant
     * @param  array<int, array{id: string, name: string, input: array<string, mixed>}>  $toolUses
     */
    public function __construct(
        public readonly array $assistantContent,
        public readonly array $toolUses,
        public readonly string $text,
        public readonly string $stopReason,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolUses !== [];
    }
}
