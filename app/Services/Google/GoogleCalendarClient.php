<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Client OAuth2 + API per Google Calendar (flusso Authorization Code scritto a
 * mano, senza SDK). Legge i "Luoghi di lavoro" (working location) giornalieri
 * dell'utente per generare in automatico le trasferte.
 *
 * A differenza di Fatture in Cloud, la connessione è PER-UTENTE: ognuno collega
 * il proprio account e vede solo il proprio calendario.
 */
class GoogleCalendarClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $redirect = null,
        private readonly ?string $scopes = null,
        private readonly ?string $hostedDomain = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            clientId: config('services.google.client_id'),
            clientSecret: config('services.google.client_secret'),
            redirect: config('services.google.redirect'),
            scopes: config('services.google.scopes'),
            hostedDomain: config('services.google.hosted_domain'),
        );
    }

    /**
     * URL a cui redirigere l'utente per autorizzare TrackFlow su Google.
     * access_type=offline + prompt=consent garantiscono il refresh token.
     */
    public function authorizeUrl(string $state): string
    {
        $this->ensureConfigured();

        $query = http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirect,
            'scope' => $this->scopes,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'hd' => $this->hostedDomain,
        ]));

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Scambia il code con access + refresh token e salva la credenziale
     * dell'utente (rimpiazzando l'eventuale connessione precedente).
     */
    public function exchangeCode(string $code, User $user): GoogleCredential
    {
        $this->ensureConfigured();

        $data = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirect,
            'code' => $code,
        ]);

        if (blank($data['refresh_token'] ?? null)) {
            throw new GoogleException('Google non ha restituito un refresh token. Revoca l\'accesso a TrackFlow dal tuo account Google e riprova.');
        }

        $credential = GoogleCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 0)),
                'google_email' => $this->fetchEmail($data['access_token']),
            ],
        );

        return $credential;
    }

    /**
     * Access token valido per l'utente, rinnovato se scaduto/in scadenza.
     */
    public function accessToken(User $user): string
    {
        $credential = GoogleCredential::forUser($user);

        if ($credential === null) {
            throw new GoogleException('Il tuo Google Calendar non è collegato.');
        }

        if ($credential->isExpired()) {
            $this->refresh($credential);
        }

        return $credential->access_token;
    }

    /**
     * Rinnova l'access token usando il refresh token salvato.
     */
    public function refresh(GoogleCredential $credential): void
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
            // Google non ruota il refresh token: se assente, si tiene il vecchio.
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 0)),
        ]);
    }

    /**
     * Luoghi di lavoro dell'utente nell'intervallo, indicizzati per giorno:
     * ['Y-m-d' => 'Etichetta luogo']. Un giorno con più eventi tiene l'ultimo.
     *
     * @return array<string, string>
     */
    public function workingLocations(User $user, Carbon $start, Carbon $end): array
    {
        $response = $this->client()
            ->withToken($this->accessToken($user))
            ->get(self::EVENTS_URL, [
                'eventTypes' => 'workingLocation',
                'timeMin' => $start->copy()->startOfDay()->toRfc3339String(),
                'timeMax' => $end->copy()->endOfDay()->toRfc3339String(),
                'singleEvents' => 'true',
                'maxResults' => 250,
                'orderBy' => 'startTime',
            ]);

        if ($response->failed()) {
            throw new GoogleException($this->errorMessage($response->json(), $response->status()));
        }

        $byDay = [];
        foreach ((array) $response->json('items', []) as $event) {
            $label = $this->extractLabel($event);
            if ($label === null) {
                continue;
            }

            foreach ($this->coveredDays($event) as $day) {
                $byDay[$day] = $label;
            }
        }

        return $byDay;
    }

    /**
     * Etichetta del luogo di lavoro (es. "Fioravanti"), qualunque sia il tipo.
     *
     * @param  array<string, mixed>  $event
     */
    private function extractLabel(array $event): ?string
    {
        $props = $event['workingLocationProperties'] ?? [];
        $type = $props['type'] ?? null;

        $label = $props['customLocation']['label']
            ?? $props['officeLocation']['label']
            ?? ($type === 'homeOffice' ? 'Casa' : null);

        $label = is_string($label) ? trim($label) : null;

        return $label !== '' ? $label : null;
    }

    /**
     * Giorni ('Y-m-d') coperti da un evento all-day (end.date è esclusivo).
     *
     * @param  array<string, mixed>  $event
     * @return array<int, string>
     */
    private function coveredDays(array $event): array
    {
        $startDate = $event['start']['date'] ?? ($event['start']['dateTime'] ?? null);
        $endDate = $event['end']['date'] ?? ($event['end']['dateTime'] ?? null);

        if ($startDate === null) {
            return [];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        // Eventi all-day: end.date è il giorno DOPO l'ultimo coperto.
        $end = $endDate !== null
            ? Carbon::parse($endDate)->startOfDay()
            : $start->copy()->addDay();

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addDay();
        }

        $days = [];
        for ($d = $start->copy(); $d->lessThan($end); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    private function fetchEmail(string $accessToken): ?string
    {
        $response = $this->client()->withToken($accessToken)->get(self::USERINFO_URL);

        return $response->successful() ? $response->json('email') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestToken(array $payload): array
    {
        $response = $this->client()->asForm()->post(self::TOKEN_URL, $payload);

        if ($response->failed()) {
            throw new GoogleException($this->errorMessage($response->json(), $response->status()));
        }

        $data = (array) $response->json();

        if (blank($data['access_token'] ?? null)) {
            throw new GoogleException('Risposta OAuth di Google priva di access token.');
        }

        return $data;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    /**
     * @param  mixed  $body
     */
    private function errorMessage($body, int $status): string
    {
        $message = null;

        if (is_array($body)) {
            $message = $body['error']['message']
                ?? $body['error_description']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null);
        }

        return $message
            ? sprintf('Google: %s', $message)
            : sprintf('Google ha risposto con errore (HTTP %d).', $status);
    }

    private function ensureConfigured(): void
    {
        if (blank($this->clientId) || blank($this->clientSecret) || blank($this->redirect)) {
            throw new GoogleException('Credenziali Google non configurate (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI).');
        }
    }
}
