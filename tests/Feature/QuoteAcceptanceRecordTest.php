<?php

use App\Console\Commands\SendQuoteReminders;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{admin: User, contact: User, quote: Quote}
 */
function scenarioAccettazione(string $status = Quote::STATUS_SENT): array
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
        'number' => 'P2026-050',
        'issue_date' => now()->subDays(6)->toDateString(),
        'description' => 'Migrazione server',
        'estimated_hours' => 10,
        'hourly_rate' => 50,
        'vat_rate' => 22,
        'status' => $status,
        'sent_at' => now()->subDays(6),
    ]);

    return ['admin' => $admin, 'contact' => $contact, 'quote' => $quote];
}

it('registra l\'accettazione arrivata per email e congela il PDF', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    ['admin' => $admin, 'quote' => $quote] = scenarioAccettazione();

    $this->actingAs($admin);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->assertActionVisible('recordAcceptance')
        ->callAction(TestAction::make('recordAcceptance'), [
            'acceptance_method' => Quote::METHOD_EMAIL,
            'acceptance_author' => 'S. Bianchi',
            'acceptance_author_role' => 'Per conto del legale rappresentante',
            'accepted_at' => now()->subDay(),
            'acceptance_note' => 'Formalizzazione su carta da far firmare al sig. Marino.',
        ])
        ->assertHasNoActionErrors();

    $quote->refresh();

    expect($quote->status)->toBe(Quote::STATUS_ACCEPTED)
        ->and($quote->acceptance_method)->toBe(Quote::METHOD_EMAIL)
        ->and($quote->acceptance_author)->toBe('S. Bianchi')
        ->and($quote->acceptance_recorded_by)->toBe($admin->id)
        ->and($quote->acceptance_recorded_at)->not->toBeNull()
        ->and($quote->accepted_at->toDateString())->toBe(now()->subDay()->toDateString())
        // Nessuno ha firmato: né firma grafica né referente che l'ha apposta.
        ->and($quote->signature_path)->toBeNull()
        ->and($quote->accepted_by)->toBeNull()
        ->and($quote->acceptanceWasRecorded())->toBeTrue()
        ->and($quote->needsFormalization())->toBeTrue();

    Storage::disk(Quote::DOCUMENTS_DISK)->assertExists($quote->pdf_path);
    expect(Storage::disk(Quote::DOCUMENTS_DISK)->get($quote->pdf_path))->toStartWith('%PDF');
});

it('ferma i solleciti automatici di firma', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    ['admin' => $admin, 'quote' => $quote] = scenarioAccettazione();

    // Inviato da 6 giorni: senza registrazione il primo sollecito partirebbe.
    expect(Quote::where('status', Quote::STATUS_SENT)->whereKey($quote->getKey())->exists())->toBeTrue();

    $this->actingAs($admin);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->callAction(TestAction::make('recordAcceptance'), [
            'acceptance_method' => Quote::METHOD_VERBAL,
            'acceptance_author' => 'Marino',
            'accepted_at' => now(),
        ]);

    $this->artisan(SendQuoteReminders::class)->assertSuccessful();

    expect($quote->fresh()->reminders_sent)->toBe(0);
});

it('scrive nel documento come è stata accettata, senza parlare di firma', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = scenarioAccettazione();

    $quote->forceFill([
        'status' => Quote::STATUS_ACCEPTED,
        'accepted_at' => now(),
        'acceptance_method' => Quote::METHOD_EMAIL,
        'acceptance_author' => 'S. Bianchi',
        'acceptance_author_role' => 'Per conto del legale rappresentante',
        'acceptance_recorded_by' => $admin->id,
        'acceptance_recorded_at' => now(),
    ])->save();

    $this->actingAs($contact)
        ->get(route('quote.document', $quote))
        ->assertOk()
        ->assertSee('Accettazione comunicata per iscritto via email')
        ->assertSee('S. Bianchi')
        ->assertDontSee('firma grafica apposta online');
});

it('fa scaricare la copia cartacea firmata al posto del PDF generato', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);

    ['admin' => $admin, 'quote' => $quote] = scenarioAccettazione(Quote::STATUS_ACCEPTED);

    $percorso = 'quotes/'.$quote->getKey().'/scansione.pdf';
    Storage::disk(Quote::DOCUMENTS_DISK)->put($percorso, '%PDF-1.4 scansione firmata');

    $quote->forceFill([
        'acceptance_method' => Quote::METHOD_PAPER,
        'acceptance_author' => 'Marino',
        'signed_copy_path' => $percorso,
    ])->save();

    expect($quote->needsFormalization())->toBeFalse();

    $this->actingAs($admin)
        ->get(route('quote.pdf', $quote))
        ->assertOk()
        ->assertDownload($quote->signedCopyFileName());
});

it('tiene la prova allegata fuori dalla vista del cliente', function () {
    Storage::fake(Quote::DOCUMENTS_DISK);

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = scenarioAccettazione(Quote::STATUS_ACCEPTED);

    $percorso = 'quotes/'.$quote->getKey().'/email-accettazione.pdf';
    Storage::disk(Quote::DOCUMENTS_DISK)->put($percorso, '%PDF-1.4 email');

    $quote->forceFill(['acceptance_evidence_path' => $percorso])->save();

    $this->actingAs($admin)->get(route('quote.evidence', $quote))->assertOk();
    $this->actingAs($contact)->get(route('quote.evidence', $quote))->assertForbidden();
});

it('non lascia registrare l\'accettazione al cliente né sui preventivi in bozza', function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    ['admin' => $admin, 'contact' => $contact, 'quote' => $quote] = scenarioAccettazione();

    $this->actingAs($contact);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->assertActionHidden('recordAcceptance')
        ->assertActionHidden('uploadSignedCopy');

    $quote->update(['status' => Quote::STATUS_DRAFT]);

    $this->actingAs($admin);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->assertActionHidden('recordAcceptance');
});

it('offre il caricamento della copia firmata solo dopo l\'accettazione', function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    ['admin' => $admin, 'quote' => $quote] = scenarioAccettazione();

    $this->actingAs($admin);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->assertActionHidden('uploadSignedCopy');

    $quote->update(['status' => Quote::STATUS_ACCEPTED]);

    Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
        ->assertActionVisible('uploadSignedCopy');
});
