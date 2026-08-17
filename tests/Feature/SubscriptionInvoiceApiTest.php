<?php

use App\Models\ApiRequestLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\SubscriptionInvoiceDraftedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const BILLING_API_TEST_SECRET = 'segreto-di-prova-lungo-abbastanza';

beforeEach(function (): void {
    config()->set('services.billing_api', [
        'secret' => BILLING_API_TEST_SECRET,
        'tolerance' => 300,
        'sources' => ['personal-ticketing'],
    ]);

    Notification::fake();

    $this->admin = User::factory()->create(['role' => 'admin']);
});

/**
 * Corpo valido, con la possibilità di sovrascrivere qualunque campo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subscriptionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'personal-ticketing',
        'source_id' => 'pay_001',
        'issued_at' => '2026-09-01',
        'period' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
        'subject' => 'Abbonamento OSAgent — piano Pro — settembre 2026',
        'vat_rate' => 22,
        'paid' => true,
        'ei_payment_method' => 'MP08',
        'customer' => [
            'name' => 'Rossi Srl',
            'vat_number' => 'IT01234567890',
            'address_street' => 'Via Roma 1',
            'address_postal_code' => '25100',
            'address_city' => 'Brescia',
            'address_province' => 'BS',
            'email' => 'amministrazione@rossi.it',
            'ei_code' => 'ABCDEFG',
        ],
        'lines' => [
            ['name' => 'Abbonamento OSAgent — piano Pro', 'qty' => 1, 'net_price' => 100],
        ],
    ], $overrides);
}

/**
 * Firma e invia la richiesta, esattamente come dovrà fare personal-ticketing.
 *
 * @param  array<string, mixed>  $body
 */
function callSubscriptionApi(array $body, ?string $secret = null, ?int $timestamp = null): TestResponse
{
    $json = (string) json_encode($body);
    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.$json, $secret ?? BILLING_API_TEST_SECRET);

    return test()->call(
        'POST',
        '/api/billing/subscription-invoices',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TRACKFLOW_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_TRACKFLOW_SIGNATURE' => 'sha256='.$signature,
        ],
        $json,
    );
}

/**
 * Il database di test non è vuoto (alcune suite non usano RefreshDatabase e
 * lasciano dati dietro di sé): qui guardiamo sempre e solo la roba nostra.
 */
function subscriptionInvoices(): Builder
{
    return Invoice::query()->where('source', 'personal-ticketing');
}

function subscriptionInvoice(): Invoice
{
    return subscriptionInvoices()->sole();
}

function subscriptionClients(): Builder
{
    return Client::query()->where('vat_number', '01234567890');
}

it('crea la fattura, il cliente e le righe', function (): void {
    $response = callSubscriptionApi(subscriptionPayload());

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('invoice.number', null)
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('invoice.taxable_amount', 100)
        ->assertJsonPath('invoice.total', 122)
        ->assertJsonPath('invoice.sent_to_fic', false)
        ->assertJsonPath('client.created', true);

    $invoice = subscriptionInvoice();

    expect($invoice->source)->toBe('personal-ticketing')
        ->and($invoice->source_id)->toBe('pay_001')
        // Intestata a un amministratore, come gli altri import automatici.
        ->and($invoice->user->role)->toBe('admin')
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->line_kind)->toBe('subscription');

    $client = subscriptionClients()->sole();

    // P.IVA normalizzata: senza prefisso paese, com'è scritta in anagrafica.
    expect($client->vat_number)->toBe('01234567890')
        ->and($client->invoicing_provider)->toBe(Client::PROVIDER_FIC);
});

it('non manda niente a Fatture in Cloud', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    $invoice = subscriptionInvoice();

    expect($invoice->isSentToFic())->toBeFalse()
        ->and($invoice->fic_document_id)->toBeNull();
});

it('avvisa gli admin che c\'è una bozza da spedire', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    Notification::assertSentTo($this->admin, SubscriptionInvoiceDraftedNotification::class);
});

it('con lo stesso source_id non crea una seconda fattura', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    callSubscriptionApi(subscriptionPayload())
        ->assertOk()
        ->assertJsonPath('created', false);

    expect(subscriptionInvoices()->count())->toBe(1);

    // E non ti riavvisa: la bozza è la stessa di prima.
    Notification::assertSentToTimes($this->admin, SubscriptionInvoiceDraftedNotification::class, 1);
});

it('riscrive le righe finché la fattura è ancora solo nostra', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    callSubscriptionApi(subscriptionPayload([
        'lines' => [
            ['name' => 'Abbonamento OSAgent — piano Pro', 'qty' => 1, 'net_price' => 150],
        ],
    ]))->assertOk();

    $invoice = subscriptionInvoice();

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->taxableAmount())->toBe(150.0);
});

it('rifiuta di modificare una fattura già inviata a FIC', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    subscriptionInvoice()->update([
        'fic_sent_at' => now(),
        'fic_document_id' => 999,
        'number' => '12/2026',
    ]);

    callSubscriptionApi(subscriptionPayload([
        'lines' => [['name' => 'Altro', 'qty' => 1, 'net_price' => 999]],
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invoice_already_sent');

    expect(subscriptionInvoice()->taxableAmount())->toBe(100.0);
});

it('riusa il cliente esistente anche se la P.IVA è scritta in un altro modo', function (): void {
    $client = Client::create([
        'name' => 'Rossi S.r.l.',
        'vat_number' => '01234567890',
        'invoicing_provider' => Client::PROVIDER_FIC,
    ]);

    callSubscriptionApi(subscriptionPayload())
        ->assertCreated()
        ->assertJsonPath('client.created', false);

    $client->refresh();

    expect(subscriptionClients()->count())->toBe(1)
        // Il nome curato in anagrafica non lo sovrascrive quello del checkout.
        ->and($client->name)->toBe('Rossi S.r.l.')
        // I campi vuoti sì: erano vuoti, adesso ci sono.
        ->and($client->address_city)->toBe('Brescia');
});

it('segnala il cliente che da qui non è fatturabile', function (): void {
    Client::create([
        'name' => 'Rossi Srl',
        'vat_number' => '01234567890',
        'invoicing_provider' => Client::PROVIDER_FISCOZEN,
    ]);

    $response = callSubscriptionApi(subscriptionPayload())->assertCreated();

    expect($response->json('warnings'))->toHaveCount(1)
        ->and($response->json('warnings.0'))->toContain('non è inviabile');
});

it('usa la modalità di pagamento SDI dichiarata dall\'API, non quella del cliente', function (): void {
    Client::create([
        'name' => 'Rossi Srl',
        'vat_number' => '01234567890',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'ei_payment_method' => 'MP05',
    ]);

    callSubscriptionApi(subscriptionPayload())->assertCreated();

    expect(subscriptionInvoice()->eiPaymentMethod())->toBe('MP08');
});

it('ricade sull\'anagrafica cliente quando l\'API non dichiara la modalità', function (): void {
    Client::create([
        'name' => 'Rossi Srl',
        'vat_number' => '01234567890',
        'invoicing_provider' => Client::PROVIDER_FIC,
        'ei_payment_method' => 'MP05',
    ]);

    $body = subscriptionPayload();
    unset($body['ei_payment_method']);

    callSubscriptionApi($body)->assertCreated();

    expect(subscriptionInvoice()->eiPaymentMethod())->toBe('MP05');
});

it('porta titolo, incasso e modalità nel payload per FIC', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();

    $payload = subscriptionInvoice()->toFicPayload();

    expect($payload['data']['visible_subject'])->toBe('Abbonamento OSAgent — piano Pro — settembre 2026')
        ->and($payload['data']['payments_list'][0]['status'])->toBe('paid')
        ->and($payload['data']['ei_data']['payment_method'])->toBe('MP08');
});

it('rifiuta una firma sbagliata senza nemmeno guardare il corpo', function (): void {
    callSubscriptionApi(subscriptionPayload(), 'segreto-diverso')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'signature_invalid');

    expect(subscriptionInvoices()->count())->toBe(0);
});

it('rifiuta una firma vecchia', function (): void {
    callSubscriptionApi(subscriptionPayload(), null, now()->subHour()->getTimestamp())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'signature_expired');
});

it('risponde 503 se il segreto non è configurato', function (): void {
    config()->set('services.billing_api.secret', '');

    callSubscriptionApi(subscriptionPayload())
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'api_disabled');
});

it('rifiuta una P.IVA non italiana', function (): void {
    callSubscriptionApi(subscriptionPayload(['customer' => ['vat_number' => 'DE123456789']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('customer.vat_number');
});

it('rifiuta una sorgente non prevista', function (): void {
    callSubscriptionApi(subscriptionPayload(['source' => 'chissachi']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');
});

it('rifiuta un totale non positivo', function (): void {
    callSubscriptionApi(subscriptionPayload([
        'lines' => [['name' => 'Sconto', 'qty' => 1, 'net_price' => -10]],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('registra ogni chiamata, comprese quelle respinte', function (): void {
    callSubscriptionApi(subscriptionPayload())->assertCreated();
    callSubscriptionApi(subscriptionPayload(['source_id' => 'pay_002']), 'segreto-diverso')->assertUnauthorized();

    expect(ApiRequestLog::count())->toBe(2);

    $rejected = ApiRequestLog::where('signature_valid', false)->sole();

    expect($rejected->status)->toBe(401)
        ->and($rejected->path)->toBe('api/billing/subscription-invoices')
        // Il corpo respinto resta leggibile: è quello che serve per capire perché.
        ->and($rejected->payload)->toContain('pay_002');
});
