<?php

use App\Models\AiUsage;
use App\Services\Ai\AiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes cost from per-million pricing with cache multipliers', function () {
    $r = app(AiUsageRecorder::class);
    // opus-4-8: input 5/M, output 25/M. 1M input + 1M output = 5 + 25 = 30.
    expect($r->cost('claude-opus-4-8', 1_000_000, 1_000_000))->toBe(30.0);
    // cache read 1M * 5 * 0.1 = 0.5 ; cache write 1M * 5 * 1.25 = 6.25
    expect($r->cost('claude-opus-4-8', 0, 0, 1_000_000, 1_000_000))->toBe(6.75);
    // modello senza prezzo → 0
    expect($r->cost('modello-ignoto', 1_000_000, 1_000_000))->toBe(0.0);
});

it('sums the monthly cost across recorded usage', function () {
    AiUsage::create(['kind' => 'assistant', 'model' => 'claude-opus-4-8', 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.12]);
    AiUsage::create(['kind' => 'foreign_invoice', 'model' => 'claude-opus-4-8', 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.03]);

    expect(round(app(AiUsageRecorder::class)->monthlyCost(), 2))->toBe(0.15);
});
