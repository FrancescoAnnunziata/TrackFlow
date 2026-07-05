<?php

use App\Models\Client;
use App\Models\FicCredential;
use App\Models\Hour;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Fic\FicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.fic', [
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'redirect' => 'http://localhost/fic/callback',
        'base_url' => 'https://api-v2.fattureincloud.it',
        'scopes' => 'issued_documents.invoices:a',
    ]);
});

it('computes the hours subtotal from the hours column', function () {
    $client = Client::create(['name' => 'Acme']);
    $invoice = Invoice::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'number' => '2026-001',
        'issue_date' => now(),
        'period_from' => now()->startOfMonth(),
        'period_to' => now()->endOfMonth(),
        'hourly_rate' => 50,
        'vat_rate' => 22,
        'status' => 'draft',
    ]);

    $hour = Hour::create([
        'user_id' => $invoice->user_id,
        'date' => now(),
        'hours' => 2.5,
        'billable' => true,
    ]);
    $hour->clients()->attach($client->id);
    $invoice->hours()->attach($hour->id);

    // 2.5h * 50 = 125 (con il vecchio bug 'minutes' sarebbe stato 0).
    expect($invoice->hoursSubtotal())->toBe(125.0);

    $payload = $invoice->toFicPayload();
    expect($payload['data']['items_list'][0]['qty'])->toBe(2.5);
});

it('refreshes an expired access token before use', function () {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'a/refreshed',
            'refresh_token' => 'r/rotated',
            'expires_in' => 86400,
        ]),
    ]);

    FicCredential::create([
        'access_token' => 'a/old',
        'refresh_token' => 'r/old',
        'expires_at' => now()->subMinute(),
        'company_id' => '999',
    ]);

    $token = FicClient::fromConfig()->accessToken();

    expect($token)->toBe('a/refreshed');
    expect(FicCredential::current()->refresh_token)->toBe('r/rotated');
    expect(FicCredential::current()->isExpired())->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://api-v2.fattureincloud.it/oauth/token'
        && $request['grant_type'] === 'refresh_token');
});

it('does not refresh a still-valid access token', function () {
    Http::fake();

    FicCredential::create([
        'access_token' => 'a/valid',
        'refresh_token' => 'r/valid',
        'expires_at' => now()->addHour(),
        'company_id' => '999',
    ]);

    expect(FicClient::fromConfig()->accessToken())->toBe('a/valid');

    Http::assertNothingSent();
});

it('creates an issued document with the invoice payload', function () {
    Http::fake([
        '*/c/*/issued_documents' => Http::response(['data' => ['id' => 555, 'token' => 'doc-tok']]),
    ]);

    FicCredential::create([
        'access_token' => 'a/valid',
        'refresh_token' => 'r/valid',
        'expires_at' => now()->addHour(),
        'company_id' => '123',
    ]);

    $client = Client::create(['name' => 'Acme', 'vat_number' => '01234567890']);
    $invoice = Invoice::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'number' => '2026-002',
        'issue_date' => now(),
        'period_from' => now()->startOfMonth(),
        'period_to' => now()->endOfMonth(),
        'hourly_rate' => 40,
        'vat_rate' => 22,
        'status' => 'draft',
    ]);
    $hour = Hour::create(['user_id' => $invoice->user_id, 'date' => now(), 'hours' => 3, 'billable' => true]);
    $hour->clients()->attach($client->id);
    $invoice->hours()->attach($hour->id);

    $document = FicClient::fromConfig()->createIssuedDocument($invoice);

    expect($document['id'])->toBe(555);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api-v2.fattureincloud.it/c/123/issued_documents'
            && $request['data']['type'] === 'invoice'
            && $request['data']['items_list'][0]['qty'] === 3.0
            && $request->hasHeader('Authorization', 'Bearer a/valid');
    });
});

it('stores credentials on a successful oauth callback', function () {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'a/new',
            'refresh_token' => 'r/new',
            'expires_in' => 86400,
        ]),
        '*/user/companies' => Http::response(['data' => ['companies' => [['id' => 42, 'name' => 'G8 Labs']]]]),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->withSession(['fic_oauth_state' => 'state-xyz'])
        ->get('/fic/callback?code=c/abc&state=state-xyz')
        ->assertRedirect();

    $credential = FicCredential::current();
    expect($credential)->not->toBeNull();
    expect($credential->access_token)->toBe('a/new');
    expect($credential->company_id)->toBe('42');
    expect($credential->company_name)->toBe('G8 Labs');
});

it('rejects an oauth callback with a mismatched state', function () {
    Http::fake();

    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->withSession(['fic_oauth_state' => 'expected'])
        ->get('/fic/callback?code=c/abc&state=tampered')
        ->assertRedirect();

    expect(FicCredential::current())->toBeNull();
    Http::assertNothingSent();
});

it('blocks non-admins from connecting', function () {
    $client = User::factory()->create(['role' => 'client']);

    $this->actingAs($client)->get('/fic/connect')->assertForbidden();
});
