<?php

use App\Filament\Resources\Corrispettivi\Pages\ListCorrispettivi;
use App\Models\Corrispettivo;
use App\Models\User;
use App\Services\Reporting\FinancialOverviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify', [
        'domain' => 'negozio-test.myshopify.com',
        'token' => 'shpat_test',
        'api_version' => '2026-01',
        'resync_days' => 14,
    ]);
});

/**
 * Una risposta GraphQL con gli ordini indicati, senza pagine successive.
 *
 * @param  array<int, array<string, mixed>>  $orders
 */
function shopifyOrders(array $orders): array
{
    return [
        'data' => [
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => $orders,
            ],
        ],
    ];
}

/**
 * Un ordine nella forma restituita da Shopify.
 */
function shopifyOrder(string $processedAt, float $total, float $refunded = 0, string $status = 'PAID', bool $test = false): array
{
    return [
        'id' => 'gid://shopify/Order/'.fake()->randomNumber(6),
        'processedAt' => $processedAt,
        'test' => $test,
        'displayFinancialStatus' => $status,
        'totalPriceSet' => ['shopMoney' => ['amount' => (string) $total]],
        'totalRefundedSet' => ['shopMoney' => ['amount' => (string) $refunded]],
    ];
}

it('somma gli ordini pagati in una riga per giorno', function () {
    Http::fake(['*/graphql.json' => Http::response(shopifyOrders([
        shopifyOrder('2026-08-20T10:00:00+02:00', 120.50),
        shopifyOrder('2026-08-20T18:30:00+02:00', 79.50),
        shopifyOrder('2026-08-21T09:00:00+02:00', 45.00),
    ]))]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-21'])
        ->assertSuccessful();

    expect(Corrispettivo::count())->toBe(2);

    $venti = Corrispettivo::whereDate('date', '2026-08-20')->first();
    expect((float) $venti->gross)->toBe(200.00)
        ->and($venti->orders_count)->toBe(2)
        ->and($venti->channel)->toBe(Corrispettivo::CHANNEL_SHOPIFY)
        ->and($venti->synced_at)->not->toBeNull();
});

it('non conta gli ordini non incassati né quelli di test', function () {
    Http::fake(['*/graphql.json' => Http::response(shopifyOrders([
        shopifyOrder('2026-08-20T10:00:00+02:00', 100.00),
        shopifyOrder('2026-08-20T11:00:00+02:00', 999.00, status: 'PENDING'),
        shopifyOrder('2026-08-20T12:00:00+02:00', 500.00, status: 'VOIDED'),
        shopifyOrder('2026-08-20T13:00:00+02:00', 777.00, test: true),
    ]))]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-20'])
        ->assertSuccessful();

    $riga = Corrispettivo::whereDate('date', '2026-08-20')->first();
    expect((float) $riga->gross)->toBe(100.00)
        ->and($riga->orders_count)->toBe(1);
});

it('sottrae i resi arrivati dopo, risincronizzando lo stesso giorno', function () {
    // Prima il solo ordine, poi — giorni dopo — lo stesso ordine con un reso:
    // è così che il sync notturno rivede un giorno già chiuso.
    Http::fake(['*/graphql.json' => Http::sequence()
        ->push(shopifyOrders([
            shopifyOrder('2026-08-20T10:00:00+02:00', 200.00),
        ]))
        ->push(shopifyOrders([
            shopifyOrder('2026-08-20T10:00:00+02:00', 200.00, refunded: 80.00, status: 'PARTIALLY_REFUNDED'),
        ])),
    ]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-20']);

    expect(Corrispettivo::whereDate('date', '2026-08-20')->first()->net)->toBe(200.00);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-20']);

    $riga = Corrispettivo::whereDate('date', '2026-08-20')->first();
    expect(Corrispettivo::count())->toBe(1)
        ->and((float) $riga->refunds)->toBe(80.00)
        ->and($riga->net)->toBe(120.00);
});

it('azzera un giorno i cui ordini sono spariti, senza creare righe vuote altrove', function () {
    Corrispettivo::create([
        'date' => '2026-08-20',
        'channel' => Corrispettivo::CHANNEL_SHOPIFY,
        'gross' => 300,
        'refunds' => 0,
        'orders_count' => 3,
    ]);

    Http::fake(['*/graphql.json' => Http::response(shopifyOrders([]))]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-18', '--to' => '2026-08-22'])
        ->assertSuccessful();

    // Solo la riga che esisteva: i giorni senza incassi non ne generano di nuove.
    expect(Corrispettivo::count())->toBe(1)
        ->and((float) Corrispettivo::first()->gross)->toBe(0.0);
});

it('lascia stare le righe inserite a mano', function () {
    Corrispettivo::create([
        'date' => '2026-08-20',
        'channel' => Corrispettivo::CHANNEL_MANUAL,
        'gross' => 50,
        'refunds' => 0,
        'orders_count' => 1,
        'notes' => 'vendita fuori Shopify',
    ]);

    Http::fake(['*/graphql.json' => Http::response(shopifyOrders([
        shopifyOrder('2026-08-20T10:00:00+02:00', 100.00),
    ]))]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-20']);

    expect(Corrispettivo::count())->toBe(2)
        ->and((float) Corrispettivo::where('channel', Corrispettivo::CHANNEL_MANUAL)->first()->gross)->toBe(50.00);
});

it('si ferma con un errore se Shopify risponde con errori GraphQL', function () {
    Http::fake(['*/graphql.json' => Http::response([
        'errors' => [['message' => 'Access denied for orders field']],
    ])]);

    $this->artisan('corrispettivi:sync', ['--from' => '2026-08-20', '--to' => '2026-08-20'])
        ->assertFailed();

    expect(Corrispettivo::count())->toBe(0);
});

it('non fa nulla se Shopify non è configurato', function () {
    config()->set('services.shopify.domain', null);
    config()->set('services.shopify.token', null);

    Http::fake();

    $this->artisan('corrispettivi:sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('somma fatture ed e-commerce nel progresso verso la soglia', function () {
    Corrispettivo::create([
        'date' => '2026-03-10',
        'channel' => Corrispettivo::CHANNEL_SHOPIFY,
        'gross' => 1000,
        'refunds' => 250,
        'orders_count' => 7,
    ]);
    Corrispettivo::create([
        'date' => '2026-07-02',
        'channel' => Corrispettivo::CHANNEL_MANUAL,
        'gross' => 500,
        'refunds' => 0,
        'orders_count' => 1,
    ]);

    $quadro = (new FinancialOverviewBuilder)->forfettario(2026);

    expect($quadro['corrispettivi_anno'])->toBe(1250.00)
        ->and($quadro['incassato_anno'])->toBe(1250.00)
        ->and($quadro['months'][2]['corrispettivi'])->toBe(750.00)
        ->and($quadro['months'][6]['corrispettivi'])->toBe(500.00)
        ->and($quadro['months'][0]['incassato'])->toBe(0.0);
});

it('mostra la lista solo agli admin', function () {
    Corrispettivo::create([
        'date' => '2026-08-20',
        'channel' => Corrispettivo::CHANNEL_SHOPIFY,
        'gross' => 200,
        'refunds' => 20,
        'orders_count' => 2,
    ]);

    Livewire::actingAs(User::factory()->create(['role' => 'admin']))
        ->test(ListCorrispettivi::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Corrispettivo::all());

    expect(User::factory()->make(['role' => 'member'])->isAdmin())->toBeFalse();
});
