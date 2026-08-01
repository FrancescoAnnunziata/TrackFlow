<?php

namespace App\Assistant;

use Anthropic\Client;
use App\Assistant\Contracts\ChatClient;
use App\Services\Ai\AiUsageRecorder;
use RuntimeException;

/**
 * Client reale verso l'API Claude. Il system prompt è diviso in due blocchi:
 * la parte statica (identica per ogni chat) è marcata con cache_control così
 * prefisso + tool restano in cache; il contesto per-thread va dopo, non cachato.
 */
class ClaudeChatClient implements ChatClient
{
    public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Chiave API Anthropic non configurata (ANTHROPIC_API_KEY).');
        }

        $system = [[
            'type' => 'text',
            'text' => $systemStatic,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
        if (trim($systemContext) !== '') {
            $system[] = ['type' => 'text', 'text' => $systemContext];
        }

        $message = (new Client(apiKey: $apiKey))->messages->create(
            maxTokens: 4096,
            messages: $messages,
            model: $model,
            system: $system,
            tools: $tools,
        );

        app(AiUsageRecorder::class)->record('assistant', $model, $message->usage);

        $assistantContent = [];
        $toolUses = [];
        $text = '';

        foreach ($message->content as $block) {
            $type = $block->type ?? null;
            if ($type === 'text') {
                $t = (string) ($block->text ?? '');
                $text .= $t;
                $assistantContent[] = ['type' => 'text', 'text' => $t];
            } elseif ($type === 'tool_use') {
                $input = (array) ($block->input ?? []);
                $assistantContent[] = [
                    'type' => 'tool_use',
                    'id' => (string) $block->id,
                    'name' => (string) $block->name,
                    'input' => $input,
                ];
                $toolUses[] = ['id' => (string) $block->id, 'name' => (string) $block->name, 'input' => $input];
            }
        }

        return new AssistantTurn($assistantContent, $toolUses, $text, (string) ($message->stopReason ?? 'end_turn'));
    }
}
