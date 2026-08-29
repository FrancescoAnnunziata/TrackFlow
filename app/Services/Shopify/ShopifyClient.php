<?php

namespace App\Services\Shopify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Lettura degli ordini dall'Admin API di Shopify (GraphQL).
 *
 * Una custom app creata dal pannello Shopify, un token permanente, solo lo
 * scope `read_orders`: non scriviamo nulla sul negozio. L'unica cosa che ci
 * interessa è quanto è entrato in un dato giorno.
 */
class ShopifyClient
{
    public function __construct(
        private readonly ?string $domain = null,
        private readonly ?string $token = null,
        private readonly ?string $apiVersion = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            domain: config('services.shopify.domain'),
            token: config('services.shopify.token'),
            apiVersion: config('services.shopify.api_version'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->domain) && filled($this->token);
    }

    /**
     * Gli ordini con data di pagamento (`processedAt`) nell'intervallo dato,
     * estremi inclusi. Segue la paginazione fino in fondo.
     *
     * Di ogni ordine tiene solo ciò che serve al conteggio: quando è stato
     * incassato, il totale, e quanto ne è stato rimborsato finora.
     *
     * @return array<int, array{processed_at: Carbon, gross: float, refunded: float}>
     */
    public function paidOrdersBetween(Carbon $from, Carbon $to): array
    {
        $this->ensureConfigured();

        $filter = sprintf(
            'processed_at:>=%s processed_at:<=%s',
            $from->copy()->startOfDay()->toIso8601String(),
            $to->copy()->endOfDay()->toIso8601String(),
        );

        $orders = [];
        $cursor = null;

        do {
            $page = $this->graphql(self::ORDERS_QUERY, [
                'query' => $filter,
                'cursor' => $cursor,
            ]);

            $connection = $page['orders'] ?? [];

            foreach ($connection['nodes'] ?? [] as $node) {
                $order = $this->normalize($node);

                if ($order !== null) {
                    $orders[] = $order;
                }
            }

            $cursor = ($connection['pageInfo']['hasNextPage'] ?? false)
                ? ($connection['pageInfo']['endCursor'] ?? null)
                : null;
        } while ($cursor !== null);

        return $orders;
    }

    /**
     * Un ordine nella forma che ci serve, oppure null se non va contato.
     *
     * Scartiamo gli ordini di test e quelli non incassati (carrelli abbandonati,
     * bonifici in attesa, ordini annullati prima del pagamento): la soglia del
     * forfettario ragiona per cassa, quindi conta solo ciò che è entrato.
     *
     * @param  array<string, mixed>  $node
     * @return array{processed_at: Carbon, gross: float, refunded: float}|null
     */
    private function normalize(array $node): ?array
    {
        if (($node['test'] ?? false) === true) {
            return null;
        }

        $incassati = ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'];

        if (! in_array($node['displayFinancialStatus'] ?? null, $incassati, true)) {
            return null;
        }

        $processedAt = $node['processedAt'] ?? null;

        if (blank($processedAt)) {
            return null;
        }

        return [
            // In ora italiana: è il fuso in cui "il giorno" ha senso qui.
            'processed_at' => Carbon::parse($processedAt)->setTimezone('Europe/Rome'),
            'gross' => (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0),
            'refunded' => (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0),
        ];
    }

    /**
     * Esegue una query GraphQL e ritorna il nodo `data`.
     *
     * GraphQL risponde 200 anche quando fallisce, con gli errori nel corpo:
     * vanno controllati a mano o si finisce per salvare zeri credendoli veri.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables): array
    {
        $response = $this->client()->post($this->endpoint(), [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            throw new ShopifyException(sprintf(
                'Shopify ha risposto %d: %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 300),
            ));
        }

        $body = (array) $response->json();

        if (! empty($body['errors'])) {
            $messaggi = array_map(
                fn ($errore) => (string) ($errore['message'] ?? 'errore sconosciuto'),
                (array) $body['errors'],
            );

            throw new ShopifyException('Shopify: '.implode(' · ', $messaggi));
        }

        return (array) ($body['data'] ?? []);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            // Shopify limita le chiamate (429) e il rate limit si ricarica in
            // pochi secondi. Su 401/404 invece riprovare non serve: è il token
            // o il dominio a essere sbagliato, e va detto subito.
            ->retry(3, 2000, function ($exception): bool {
                $status = $exception instanceof RequestException
                    ? $exception->response->status()
                    : 0;

                return $status === 429 || $status >= 500 || $status === 0;
            }, throw: false)
            ->withHeaders(['X-Shopify-Access-Token' => (string) $this->token]);
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            trim((string) $this->domain, '/'),
            $this->apiVersion,
        );
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ShopifyException(
                'Shopify non è configurato: servono SHOPIFY_SHOP_DOMAIN e SHOPIFY_ADMIN_API_TOKEN.'
            );
        }
    }

    private const ORDERS_QUERY = <<<'GRAPHQL'
        query TrackFlowOrders($query: String!, $cursor: String) {
          orders(first: 250, after: $cursor, query: $query, sortKey: PROCESSED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              processedAt
              test
              displayFinancialStatus
              totalPriceSet { shopMoney { amount } }
              totalRefundedSet { shopMoney { amount } }
            }
          }
        }
        GRAPHQL;
}
