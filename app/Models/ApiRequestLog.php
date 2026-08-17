<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una chiamata ricevuta da un'API in ingresso. Scritta anche quando la
 * richiesta viene rifiutata: le chiamate respinte sono quelle che servirà
 * leggere per capire perché un pagamento non è diventato fattura.
 */
class ApiRequestLog extends Model
{
    /** Oltre questa soglia corpo e risposta vengono troncati nel log. */
    private const MAX_BODY_CHARS = 64_000;

    protected $fillable = [
        'source',
        'method',
        'path',
        'ip',
        'signature_valid',
        'status',
        'payload',
        'response',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'status' => 'integer',
    ];

    /**
     * Tronca i corpi troppo lunghi: il log deve restare leggibile e non
     * trasformarsi in un archivio di allegati.
     */
    public static function truncate(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        return mb_strlen($body) > self::MAX_BODY_CHARS
            ? mb_substr($body, 0, self::MAX_BODY_CHARS)."\n… (troncato)"
            : $body;
    }
}
