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

        $payload = $invoice->toFicPayload();

        // FIC è la fonte della numerazione: usa il prossimo progressivo della
        // serie (niente numeri decisi/forzati da TrackFlow, così è senza buchi).
        $year = optional($invoice->issue_date)->year ?? now()->year;
        $next = $this->nextInvoiceNumber($year);
        if ($next !== null) {
            $payload['data']['number'] = $next;
            $payload['data']['numeration'] = '';
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->post(
                sprintf('%s/c/%s/issued_documents', $this->baseUrl, $credential->company_id),
                $payload,
            );

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Nodo `info` degli issued documents: numerazioni, tipi IVA, metodi di
     * pagamento, ecc. Leggibile con lo scope attuale.
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $credential = FicCredential::current();

        if ($credential === null || blank($credential->company_id)) {
            throw new FicException('Connessione a Fatture in Cloud non configurata correttamente.');
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->get(sprintf('%s/c/%s/issued_documents/info', $this->baseUrl, $credential->company_id), [
                'type' => 'invoice',
            ]);

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Prossimo numero della serie default per l'anno, secondo FIC.
     */
    public function nextInvoiceNumber(int $year): ?int
    {
        $numerations = (array) ($this->info()['numerations'] ?? []);
        $forYear = (array) ($numerations[$year] ?? $numerations[(string) $year] ?? []);
        $next = $forYear[''] ?? null;

        return $next !== null ? (int) $next : null;
    }

    /**
     * Elenca le fatture emesse (una pagina). Ritorna il nodo `data`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(int $page = 1, int $perPage = 50): array
    {
        $credential = FicCredential::current();

        if ($credential === null || blank($credential->company_id)) {
            throw new FicException('Connessione a Fatture in Cloud non configurata correttamente.');
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->get(sprintf('%s/c/%s/issued_documents', $this->baseUrl, $credential->company_id), [
                'type' => 'invoice',
                'per_page' => $perPage,
                'page' => $page,
                'sort' => '-date',
            ]);

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Dettaglio completo di una fattura emessa (con items_list).
     *
     * @return array<string, mixed>
     */
    public function getInvoice(int $documentId): array
    {
        $credential = FicCredential::current();

        $response = $this->client()
            ->withToken($this->accessToken())
            ->get(sprintf('%s/c/%s/issued_documents/%d', $this->baseUrl, $credential->company_id, $documentId));

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Elenca i documenti ricevuti (fatture passive/spese) di un tipo, una
     * pagina. FIC richiede sempre il parametro `type`. Ritorna il nodo `data`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listReceivedDocuments(string $type = 'expense', int $page = 1, int $perPage = 50): array
    {
        $credential = FicCredential::current();

        if ($credential === null || blank($credential->company_id)) {
            throw new FicException('Connessione a Fatture in Cloud non configurata correttamente.');
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->get(sprintf('%s/c/%s/received_documents', $this->baseUrl, $credential->company_id), [
                'type' => $type,
                'per_page' => $perPage,
                'page' => $page,
                'sort' => '-date',
            ]);

        if ($response->failed()) {
            throw new FicException($this->errorMessage($response->json(), $response->status()));
        }

        return (array) $response->json('data', []);
    }

    /**
     * Dettaglio completo di un documento ricevuto (con items_list). FIC
     * richiede il `type` anche sul dettaglio.
     *
     * @return array<string, mixed>
     */
    public function getReceivedDocument(int $documentId, string $type = 'expense'): array
    {
        $credential = FicCredential::current();

        if ($credential === null || blank($credential->company_id)) {
            throw new FicException('Connessione a Fatture in Cloud non configurata correttamente.');
        }

        $response = $this->client()
            ->withToken($this->accessToken())
            ->get(sprintf('%s/c/%s/received_documents/%d', $this->baseUrl, $credential->company_id, $documentId), [
                'type' => $type,
            ]);

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

            // Dettagli di validazione FIC (data.items_list.0.vat.id: [...]).
            $validation = $body['error']['validation_result'] ?? null;
            if (is_array($validation)) {
                $details = [];
                array_walk_recursive($validation, function ($value) use (&$details): void {
                    if (is_string($value)) {
                        $details[] = $value;
                    }
                });
                if ($details !== []) {
                    $message = trim(($message ? $message.' — ' : '').implode(' ', array_slice($details, 0, 4)));
                }
            }
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
