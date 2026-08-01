<?php

namespace App\Assistant\Contracts;

use App\Assistant\AssistantTurn;

/**
 * Astrazione della chiamata al modello, così il runner è testabile con un fake.
 */
interface ChatClient
{
    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn;
}
