<?php

namespace App\Assistant;

/**
 * Uno strumento a disposizione dell'assistente. I tool sono di sola lettura,
 * tranne la proposta di riconciliazione che NON scrive: prepara solo una
 * proposta da confermare. Gli id vanno sempre rivalidati con query reali.
 */
interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON schema dell'input */
    public function inputSchema(): array;

    /** @param array<string, mixed> $input */
    public function run(array $input): AssistantToolResult;
}
