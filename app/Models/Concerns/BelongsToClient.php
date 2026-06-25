<?php

namespace App\Models\Concerns;

use App\Models\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lega un model al "tenant" del gestionale, ovvero il Client.
 *
 * Coerentemente con il resto del progetto non viene applicato un global scope:
 * l'isolamento in lettura resta esplicito nelle Resource Filament
 * (getEloquentQuery) e nelle Policy. Qui ci limitiamo a:
 *  - esporre la relazione client();
 *  - valorizzare automaticamente client_id quando chi crea il record e' un
 *    utente "client" e il campo non e' gia' stato impostato.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::creating(function ($model): void {
            if (! empty($model->client_id)) {
                return;
            }

            $user = auth()->user();

            if ($user && $user->isClient() && $user->client_id) {
                $model->client_id = $user->client_id;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
