<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    protected $fillable = ['assistant_thread_id', 'role', 'content', 'status', 'steps', 'actions'];

    protected $casts = [
        'steps' => 'array',
        'actions' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AssistantThread::class, 'assistant_thread_id');
    }
}
