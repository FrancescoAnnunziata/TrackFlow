<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenziali OAuth2 di Google Calendar per un singolo utente. Ogni utente
 * collega il proprio account per leggere i Luoghi di lavoro giornalieri.
 */
class GoogleCredential extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'google_email',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Credenziale dell'utente indicato, se presente.
     */
    public static function forUser(User $user): ?self
    {
        return static::query()->where('user_id', $user->id)->first();
    }

    /**
     * True se l'access token è scaduto o sta per scadere (margine di 60s).
     */
    public function isExpired(): bool
    {
        return $this->expires_at === null
            || $this->expires_at->isBefore(now()->addSeconds(60));
    }
}
