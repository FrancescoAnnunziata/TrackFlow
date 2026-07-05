<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credenziali OAuth2 di Fatture in Cloud. Tabella a riga singola: TrackFlow è
 * collegato a una sola azienda FIC alla volta.
 */
class FicCredential extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
        'company_id',
        'company_name',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    /**
     * La (unica) credenziale salvata, se presente.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * True se TrackFlow è collegato a Fatture in Cloud.
     */
    public static function isConnected(): bool
    {
        return static::current() !== null;
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
