<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'model',
        'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens', 'cost',
    ];

    protected $casts = ['cost' => 'float'];
}
