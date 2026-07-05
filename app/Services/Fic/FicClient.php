<?php

namespace App\Services\Fic;

use App\Models\FicCredential;
use App\Models\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client OAuth2 + API per Fatture in Cloud (API v2).
 *
 * Gestisce il flusso Authorization Code scritto a mano (nessun SDK): URL di
 * autorizzazione, scambio del code, refresh automatico dell'access token, e la
 * creazione del documento emesso riusando l'array di Invoice::toFicPayload().
 */
class FicClient
{
    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $redirect = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $scopes = null,
    ) {}

    /**
     * Crea un'istanza con i valori da config/services.php.
     */
    public static function fromConfig(): self
    {
        return new self(
            clientId: config('services.fic.client_id'),
            clientSecret: config('services.fic.client_secret'),
            redirect: config('services.fic.redirect'),
            baseUrl: rtrim((string) config('services.fic.base_url'), '/'),
            scopes: config('services.fic.scopes'),
        );
    }

    /**
     * URL a cui redirigere l'utente per autorizzare TrackFlow su FIC.
     */
    public function authorizeUrl(string $state): string
    {
        $this->ensureConfigured();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirect,
            'scope' => $this->scopes,
            'state' => $state,
        ]);

        return $this->baseUrl.'/oauth/authorize?'.$query;
    }

    /**
     * Scambia il code di autorizzazione con access + refresh token, ricava
     * l'azienda collegata e salva la credenziale (rimpiazzando l'eventuale
     * connessione precedente).
     */
    public function exchangeCode(string $code): FicCredential
    {
        $this->ensureConfigured();

        $data = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirect,
            'code' => $code,
        ]);

        // Una sola connessione attiva alla volta.
        FicCredential::query()->delete();

        $credential = FicCredential::create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 0)),
        ]);

        $company = $this->firstCompany($credential->access_token);

        if ($company !== null) {
            $credential->update([
                'company_id' => (string) ($company['id'] ?? ''),
                'company_name' => $company['name'] ?? null,
            ]);
        }

        return $credential;
    }

    /**
     * Ritorna un access token valido, rinnovandolo se scaduto/in scadenza.
     */
    public function accessToken(): string
    {
        $credential = FicCredential::current();

        if ($credential === null) {
            throw new FicException('TrackFlow non è collegato a Fatture in Cloud.');
        }

        if ($credential->isExpired()) {
            $this->refresh($credential);
        }

        return $credential->access_token;
    }

    /**
     * Rinnova l'access token usando il refresh token salvato.
     */
    public function refresh(FicCredential $credential): void
    {
        $this->ensureConfigured();

        $data = $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $credential->refresh_token,
        ]);

        $credential->update([
            'access_token' => $data['access_token'],
            // FIC ruota anche il refresh token: se assente, si tiene il vecchio.
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 0)),
        ]);
    }

    /**
     * Crea la fattura su Fatture in Cloud (documento registrato, non SDI) e
     * ritorna il nodo `data` della risposta.
     *
     * @return array<string, mixed>
     */
    public function createIssuedDocument(Invoice $invoice): array
    {
        $credential = FicCredential::current();

        if ($credential === null || blank($credential->company_id)) {
            throw new FicException('Connessione a Fatture in Cloud non configurata correttamente.');
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->post(
                sprintf('%s/c/%s/issued_documents', $this->baseUrl, $credential->company_id),
                $invoice->toFicPayload(),
            );

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Prima azienda associata all'utente autenticato.
     *
     * @return array<string, mixed>|null
     */
    private function firstCompany(string $accessToken): ?array
    {
        $response = $this->client()
            ->withToken($accessToken)
            ->get($this->baseUrl.'/user/companies');

        if ($response->failed()) {
            return null;
        }

        $companies = $response->json('data.companies', []);

        return $companies[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestToken(array $payload): array
    {
        $response = $this->client()
            ->asJson()
            ->post($this->baseUrl.'/oauth/token', $payload);

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        $data = (array) $response->json();

        if (blank($data['access_token'] ?? null)) {
            throw new FicException('Risposta OAuth di Fatture in Cloud priva di access token.');
        }

        return $data;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    /**
     * Estrae un messaggio d'errore leggibile dalla risposta FIC.
     *
     * @param  mixed  $body
     */
    private function errorMessage($body, int $status): string
    {
        $message = null;

        if (is_array($body)) {
            $message = $body['error']['message']
                ?? $body['error_description']
                ?? $body['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null);
        }

        return $message
            ? sprintf('Fatture in Cloud: %s', $message)
            : sprintf('Fatture in Cloud ha risposto con errore (HTTP %d).', $status);
    }

    private function ensureConfigured(): void
    {
        if (blank($this->clientId) || blank($this->clientSecret) || blank($this->redirect)) {
            throw new FicException('Credenziali Fatture in Cloud non configurate (FIC_CLIENT_ID / FIC_CLIENT_SECRET / FIC_REDIRECT_URI).');
        }
    }
}
