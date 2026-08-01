<?php

namespace App\Services\Ai;

use Anthropic\Messages\Usage;
use App\Models\AiUsage;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Registra una riga per ogni chiamata Anthropic con i token e il costo calcolato,
 * per tracciare la spesa AI. Best-effort: un errore di logging non deve mai
 * rompere il lavoro AI che sta misurando.
 */
class AiUsageRecorder
{
    /**
     * @param  string  $kind  assistant | foreign_invoice | issued_invoice
     */
    public function record(string $kind, string $model, Usage $usage): void
    {
        try {
            $input = (int) $usage->inputTokens;
            $output = (int) $usage->outputTokens;
            $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
            $cacheWrite = (int) ($usage->cacheCreationInputTokens ?? 0);

            AiUsage::create([
                'user_id' => auth()->id(),
                'kind' => $kind,
                'model' => $model,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_read_input_tokens' => $cacheRead,
                'cache_creation_input_tokens' => $cacheWrite,
                'cost' => $this->cost($model, $input, $output, $cacheRead, $cacheWrite),
            ]);
        } catch (Throwable) {
            // Il logging dell'uso non deve mai rompere la chiamata AI che misura.
        }
    }

    /**
     * Costo in USD dai listini Anthropic per milione di token. `input` è il testo
     * NON in cache; i token in cache sono prezzati coi loro moltiplicatori. Modelli
     * senza prezzo configurato costano 0 (non si tira a indovinare).
     */
    public function cost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $rates = config("services.anthropic.pricing.$model");
        if ($rates === null) {
            return 0.0;
        }

        $inputRate = (float) $rates['input'];
        $outputRate = (float) $rates['output'];
        $readMult = (float) config('services.anthropic.cache_read_multiplier', 0.1);
        $writeMult = (float) config('services.anthropic.cache_write_multiplier', 1.25);

        $cost = $input * $inputRate
            + $output * $outputRate
            + $cacheRead * $inputRate * $readMult
            + $cacheWrite * $inputRate * $writeMult;

        return round($cost / 1_000_000, 6);
    }

    /** Costo AI totale del mese corrente (USD). */
    public function monthlyCost(): float
    {
        return (float) AiUsage::where('created_at', '>=', Carbon::now()->startOfMonth())->sum('cost');
    }
}
