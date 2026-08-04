<?php

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteDecidedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * PNG 1x1 valido, come quello che il canvas produce al submit.
 */
function signatureDataUri(): string
{
    return 'data:image/png;base64,'
        .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

/**
 * @return array{admin: User, contact: User, quote: Quote}
 */
function quoteScenario(string $status = Quote::STATUS_SENT): array
{
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Giorgio']);
    $client = Client::create(['name' => 'Acme SpA', 'vat_number' => 'IT01234567890']);
    $contact = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
        'name' => 'Mario',
        'surname' => 'Rossi',
    ]);

    $quote = Quote::create([
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'number' => 'P2026-001',
        'issue_date' => now()->toDateString(),
        'description' => "Migrazione server\nSetup backup",
        'estimated_hours' => 10,
        'hourly_rate' => 50,
        'vat_rate' => 22,
        'status' => $status,
        'sent_at' => $status === Quote::STATUS_DRAFT ? null : now(),
    ]);

    return ['admin' => $admin, 'contact' => $contact, 'quote' => $quote];
}

it('mostra al referente il documento con il riquadro per firmare', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->actingAs($contact)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('PREVENTIVO')
        ->assertSee('P2026-001')
        ->assertSee('Acme SpA')
        ->assertSee('Firma e invia')
        ->assertSee('€ 610,00'); // 10 h × 50 € + IVA 22%

    expect($quote->fresh()->document_viewed_at)->not->toBeNull();
});

it('firma il preventivo: salva firma e PDF, e avvisa entrambe le parti', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Notification::fake();

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->actingAs($contact)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Mario Rossi',
            'signer_role' => 'Amministratore',
            'signature' => signatureDataUri(),
            'accept' => '1',
        ])
        ->assertRedirect(route('quote.document', $quote));

    $quote->refresh();

    expect($quote->status)->toBe(Quote::STATUS_ACCEPTED)
        ->and($quote->accepted_by)->toBe($contact->id)
        ->and($quote->signer_name)->toBe('Mario Rossi')
        ->and($quote->signer_role)->toBe('Amministratore')
        ->and($quote->accepted_at)->not->toBeNull()
        ->and($quote->signature_ip)->not->toBeNull();

    Storage::disk(Quote::DOCUMENTS_DISK)->assertExists($quote->signature_path);
    Storage::disk(Quote::DOCUMENTS_DISK)->assertExists($quote->pdf_path);

    expect(Storage::disk(Quote::DOCUMENTS_DISK)->get($quote->pdf_path))->toStartWith('%PDF');

    Notification::assertSentTo($admin, QuoteDecidedNotification::class);
    Notification::assertSentTo($contact, QuoteDecidedNotification::class);
});

it('rifiuta la firma senza spunta di accettazione o senza tratto', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);

    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->actingAs($contact)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Mario Rossi',
            'signature' => signatureDataUri(),
        ])
        ->assertSessionHasErrors('accept');

    $this->actingAs($contact)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Mario Rossi',
            'signature' => 'data:image/png;base64,non-un-png',
            'accept' => '1',
        ])
        ->assertSessionHasErrors('signature');

    expect($quote->fresh()->status)->toBe(Quote::STATUS_SENT);
});

it('non lascia firmare due volte lo stesso preventivo', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Notification::fake();

    ['contact' => $contact, 'quote' => $quote] = quoteScenario(Quote::STATUS_ACCEPTED);

    $this->actingAs($contact)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Mario Rossi',
            'signature' => signatureDataUri(),
            'accept' => '1',
        ])
        ->assertStatus(409);
});

it('registra il rifiuto con il motivo e avvisa entrambe le parti', function () {
    Notification::fake();

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->actingAs($contact)
        ->post(route('quote.reject', $quote), ['rejection_reason' => 'Budget non approvato'])
        ->assertRedirect(route('quote.document', $quote));

    $quote->refresh();

    expect($quote->status)->toBe(Quote::STATUS_REJECTED)
        ->and($quote->rejection_reason)->toBe('Budget non approvato')
        ->and($quote->rejected_at)->not->toBeNull();

    Notification::assertSentTo($admin, QuoteDecidedNotification::class);
    Notification::assertSentTo($contact, QuoteDecidedNotification::class);
});

it('nega documento, firma e PDF a chi non è parte del preventivo', function () {
    ['quote' => $quote] = quoteScenario();
    $estraneo = User::factory()->create(['role' => 'client', 'client_id' => Client::create(['name' => 'Altro'])->id]);

    $this->actingAs($estraneo)->get(route('quote.document', $quote))->assertForbidden();
    $this->actingAs($estraneo)->get(route('quote.pdf', $quote))->assertForbidden();
    $this->actingAs($estraneo)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Chiunque',
            'signature' => signatureDataUri(),
            'accept' => '1',
        ])
        ->assertForbidden();
});

it('lascia vedere il documento all\'admin ma non lo fa firmare al posto del cliente', function () {
    ['admin' => $admin, 'quote' => $quote] = quoteScenario();

    $this->actingAs($admin)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('come lo vede il cliente')
        ->assertDontSee('Firma e invia');

    $this->actingAs($admin)
        ->post(route('quote.sign', $quote), [
            'signer_name' => 'Giorgio',
            'signature' => signatureDataUri(),
            'accept' => '1',
        ])
        ->assertForbidden();

    // L'apertura da parte dell'admin non conta come "visto dal cliente".
    expect($quote->fresh()->document_viewed_at)->toBeNull();
});

it('scarica il PDF del preventivo, firmato o meno', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Notification::fake();

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = quoteScenario();

    $nonFirmato = $this->actingAs($admin)->get(route('quote.pdf', $quote));
    $nonFirmato->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($nonFirmato->streamedContent())->toStartWith('%PDF');

    $this->actingAs($contact)->post(route('quote.sign', $quote), [
        'signer_name' => 'Mario Rossi',
        'signature' => signatureDataUri(),
        'accept' => '1',
    ]);

    $this->actingAs($contact)
        ->get(route('quote.pdf', $quote))
        ->assertOk()
        ->assertDownload($quote->fresh()->pdfFileName());
});

it('intesta il documento a g8labs di default e all\'intestazione scelta altrimenti', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->actingAs($contact)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('g8labs srl unipersonale')
        ->assertDontSee('Giorgio Giotto');

    $quote->update(['issuer_key' => 'giorgio']);

    $this->actingAs($contact)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('Giorgio Giotto')
        ->assertDontSee('g8labs srl unipersonale');

    // Un'intestazione non più configurata non rompe i documenti vecchi.
    $quote->update(['issuer_key' => 'sparita']);

    $this->actingAs($contact)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('g8labs srl unipersonale');
});

it('stampa solo i dati compilati dell\'intestazione', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    // Senza P.IVA configurata la riga non deve comparire...
    $this->actingAs($contact)->get(route('quote.document', $quote))->assertDontSee('P.IVA IT9');

    config()->set('azienda.emittenti.g8labs.partita_iva', 'IT99999999999');

    // ...con la P.IVA compilata, sì.
    $this->actingAs($contact)->get(route('quote.document', $quote))->assertSee('P.IVA IT99999999999');
});

it('apre il documento dal link della mail senza chiedere il login', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->get($quote->magicLinkFor($contact))
        ->assertOk()
        ->assertSee('Firma e invia');

    expect(auth()->id())->toBe($contact->id);
});

it('lascia firmare chi è entrato dal link della mail, senza password', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Notification::fake();

    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    // Nessun actingAs: l'unica credenziale è il link ricevuto via email.
    $this->get($quote->magicLinkFor($contact))->assertOk();

    $this->post(route('quote.sign', $quote), [
        'signer_name' => 'Mario Rossi',
        'signature' => signatureDataUri(),
        'accept' => '1',
    ])->assertRedirect(route('quote.document', $quote));

    expect($quote->fresh()->status)->toBe(Quote::STATUS_ACCEPTED)
        ->and($quote->fresh()->accepted_by)->toBe($contact->id);
});

it('scarica il PDF dal link firmato anche senza sessione', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);

    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $this->get(URL::temporarySignedRoute(
        'quote.pdf',
        now()->addDays(Quote::MAGIC_LINK_DAYS),
        ['quote' => $quote->getKey(), 'user' => $contact->getKey()],
    ))->assertOk();
});

it('spiega che il link è scaduto invece di rimbalzare sul login', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $link = $quote->magicLinkFor($contact);

    $this->travel(Quote::MAGIC_LINK_DAYS + 1)->days();

    $this->get($link)
        ->assertStatus(403)
        ->assertSee('Questo link non è più valido')
        ->assertDontSee('password', false);

    // Stesso trattamento per chi arriva sull'URL nudo, senza firma.
    $this->get(route('quote.document', $quote))
        ->assertStatus(403)
        ->assertSee('Questo link non è più valido');
});

it('tiene in piedi i link delle email già spedite', function () {
    ['contact' => $contact, 'quote' => $quote] = quoteScenario();

    $vecchioLink = URL::temporarySignedRoute(
        'quote.magic',
        now()->addDays(Quote::MAGIC_LINK_DAYS),
        ['quote' => $quote->getKey(), 'user' => $contact->getKey()],
    );

    $this->get($vecchioLink)->assertRedirect(route('quote.document', $quote));

    expect(auth()->id())->toBe($contact->id);
});
