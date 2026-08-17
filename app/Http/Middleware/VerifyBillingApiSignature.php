<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticazione delle API in ingresso: firma HMAC del corpo con un segreto
 * condiviso, non un token utente.
 *
 * Il motivo è che il chiamante è un server, non una persona: un token da solo,
 * se finisce nei log di un proxy, vale per sempre e per chiunque. La firma
 * copre anche il corpo (nessuno può modificare gli importi in transito) ed è
 * datata (una richiesta catturata non è rigiocabile domani).
 *
 * Qualunque esito — accettata, respinta, esplosa — finisce in api_request_logs.
 */
class VerifyBillingApiSignature
{
    public const TIMESTAMP_HEADER = 'X-TrackFlow-Timestamp';

    public const SIGNATURE_HEADER = 'X-TrackFlow-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.billing_api.secret', '');

        if ($secret === '') {
            return $this->reject($request, 503, 'api_disabled', 'API di fatturazione non configurata su questo ambiente.');
        }

        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($timestamp === '' || $signature === '') {
            return $this->reject($request, 401, 'signature_missing', 'Richiesta senza firma: servono gli header '.self::TIMESTAMP_HEADER.' e '.self::SIGNATURE_HEADER.'.');
        }

        $tolerance = max(1, (int) config('services.billing_api.tolerance', 300));

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return $this->reject($request, 401, 'signature_expired', "Firma fuori tempo massimo ({$tolerance}s). Controlla l'orologio del server chiamante.");
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        // Str::after restituisce l'intera stringa se il prefisso non c'è:
        // accettiamo sia "sha256=<hex>" sia l'esadecimale nudo.
        if (! hash_equals($expected, Str::after($signature, 'sha256='))) {
            return $this->reject($request, 401, 'signature_invalid', 'Firma non valida.');
        }

        $response = $next($request);

        $this->log($request, $response->getStatusCode(), true, $response->getContent() ?: null);

        return $response;
    }

    /**
     * Risposta di rifiuto, nello stesso formato d'errore del controller.
     */
    private function reject(Request $request, int $status, string $code, string $message): Response
    {
        $this->log($request, $status, false, json_encode(['error' => ['code' => $code]]));

        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    private function log(Request $request, int $status, bool $signatureValid, ?string $response): void
    {
        // Dichiarato dal chiamante e non verificato: è un'etichetta per
        // ritrovare la chiamata, non un'identità. Su un corpo malformato può
        // essere qualunque cosa, quindi lo prendiamo solo se è una stringa.
        $source = $request->input('source');

        try {
            ApiRequestLog::create([
                'source' => is_string($source) && $source !== '' ? Str::limit($source, 100, '') : null,
                'method' => $request->method(),
                'path' => Str::limit($request->path(), 250, ''),
                'ip' => $request->ip(),
                'signature_valid' => $signatureValid,
                'status' => $status,
                'payload' => ApiRequestLog::truncate($request->getContent()),
                'response' => ApiRequestLog::truncate($response),
            ]);
        } catch (\Throwable $e) {
            // Il log non deve mai far fallire una fattura già accettata.
            Log::error('Log della chiamata API non scritto: '.$e->getMessage());
        }
    }
}
