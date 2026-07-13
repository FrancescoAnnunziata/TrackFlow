<?php

use App\Filament\Pages\FattureEstere;
use App\Jobs\ExtractForeignInvoicesJob;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\ForeignInvoiceExtractor;
use App\Services\CurrencyConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function mockExtractor(): void
{
    $mock = Mockery::mock(ForeignInvoiceExtractor::class);
    $mock->shouldReceive('configured')->andReturn(true);
    $mock->shouldReceive('extract')->andReturnUsing(function (string $pdf): array {
        // Il fix è corretto solo se qui arriva un PDF NON vuoto.
        expect($pdf)->not->toBe('');

        return [
            'supplier_name' => 'SiteGround Spain S.L.', 'supplier_vat' => 'ESB87194171',
            'number' => '4535410', 'document_date' => '2026-03-19',
            'amount_net' => 12.99, 'amount_vat' => 0.0, 'amount_gross' => 12.99,
            'currency' => 'EUR', 'category' => 'Software e abbonamenti cloud',
            'description' => 'Rinnovo dominio', 'is_credit_note' => false,
        ];
    });
    app()->instance(ForeignInvoiceExtractor::class, $mock);
}

it('extracts via a queued job and fills the review rows on the next poll', function () {
    Storage::fake('public');
    mockExtractor();
    $this->actingAs(User::factory()->admin()->create());

    // Queue sync (test): il job gira inline al dispatch; poi il polling
    // (checkExtraction) trova il risultato in cache e popola le righe.
    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('sg.pdf', '%PDF-1.4 contenuto finto')])
        ->call('estrai')
        ->assertHasNoErrors()
        ->assertSet('extracting', true)
        ->call('checkExtraction')
        ->assertSet('extracting', false)
        ->assertSet('data.rows', function (array $rows): bool {
            $first = collect($rows)->first();

            return count($rows) === 1
                && $first['supplier_name'] === 'SiteGround Spain S.L.'
                && $first['number'] === '4535410'
                && $first['currency'] === 'EUR';
        });
});

it('enqueues the extraction job instead of running it in the web request', function () {
    Storage::fake('public');
    Queue::fake();
    mockExtractor();
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('sg.pdf', '%PDF-1.4 x')])
        ->call('estrai')
        ->assertHasNoErrors()
        ->assertSet('extracting', true);

    Queue::assertPushed(ExtractForeignInvoicesJob::class);
});

it('the job extracts each PDF and writes the rows to cache', function () {
    Storage::fake('public');
    mockExtractor();
    Storage::disk('public')->put('passive-attachments/sg.pdf', '%PDF-1.4 reale');

    $key = 'estere-extract:test';
    (new ExtractForeignInvoicesJob(['passive-attachments/sg.pdf'], $key))
        ->handle(app(ForeignInvoiceExtractor::class), app(CurrencyConverter::class));

    $result = Cache::get($key);
    expect($result['status'])->toBe('done');
    expect($result['errors'])->toBe(0);
    expect(collect($result['rows'])->first()['number'])->toBe('4535410');
});

it('keeps the uploaded PDF on disk after creating the invoice (not deleted)', function () {
    Storage::fake('public');
    mockExtractor();
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire\Livewire::test(FattureEstere::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('sg.pdf', '%PDF-1.4 reale')])
        ->call('estrai')
        ->call('checkExtraction');

    $attachment = collect($component->get('data')['rows'])->first()['attachment'];
    expect(Storage::disk('public')->exists($attachment))->toBeTrue();

    $component->call('crea')->assertHasNoErrors();

    // Il PDF resta su disco ed è collegato alla fattura (giustificativo).
    expect(PassiveInvoice::first()->attachment)->toBe($attachment);
    expect(Storage::disk('public')->exists($attachment))->toBeTrue();
});

it('creates the passive invoice with supplier and attachment from the reviewed rows', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.rows', [[
            'attachment' => 'passive-attachments/sg.pdf',
            'supplier_name' => 'SiteGround Spain S.L.', 'supplier_vat' => 'ESB87194171',
            'number' => '4535410', 'document_date' => '2026-03-19',
            'category' => 'Software e abbonamenti cloud', 'currency' => 'EUR',
            'amount_net' => 12.99, 'amount_vat' => 0, 'amount_gross' => 12.99,
        ]])
        ->call('crea')
        ->assertHasNoErrors();

    $invoice = PassiveInvoice::first();
    expect($invoice)->not->toBeNull();
    expect($invoice->number)->toBe('4535410');
    expect($invoice->currency)->toBe('EUR');
    expect($invoice->attachment)->toBe('passive-attachments/sg.pdf');
    expect($invoice->type)->toBe('expense');
    expect(Supplier::where('name', 'SiteGround Spain S.L.')->exists())->toBeTrue();
});

it('converts a foreign amount to EUR via the ECB reference rate', function () {
    Http::fake([
        '*/2025-11-15*' => Http::response(['amount' => 1, 'base' => 'USD', 'date' => '2025-11-14', 'rates' => ['EUR' => 0.8618]]),
    ]);
    $fx = app(CurrencyConverter::class);

    expect($fx->toEur(228, 'USD', '2025-11-15'))->toBe(196.49); // 228 * 0.8618
    // EUR o importo nullo: nessuna conversione (niente chiamata di rete).
    expect($fx->toEur(100, 'EUR', '2025-11-15'))->toBeNull();
});

it('registers a foreign-currency invoice in EUR keeping the original as reference', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.rows', [[
            'attachment' => 'passive-attachments/webflow.pdf',
            'supplier_name' => 'Webflow, Inc.', 'supplier_vat' => null,
            'number' => '1763199638', 'document_date' => '2025-11-15',
            'category' => 'Software e abbonamenti cloud', 'currency' => 'USD',
            'amount_net' => 228, 'amount_vat' => 0, 'amount_gross' => 228,
            'amount_eur' => 196.48, 'is_credit_note' => false,
        ]])
        ->call('crea')
        ->assertHasNoErrors();

    $inv = PassiveInvoice::first();
    expect($inv->currency)->toBe('EUR');
    expect((float) $inv->amount_gross)->toBe(196.48);
    expect($inv->original_currency)->toBe('USD');
    expect((float) $inv->original_amount)->toBe(228.0);
});

it('creates a passive credit note when the row is flagged as such', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.rows', [[
            'attachment' => 'passive-attachments/anthropic-cn.pdf',
            'supplier_name' => 'Anthropic, PBC', 'supplier_vat' => null,
            'number' => 'VQXFWWQB-0004-CN-01', 'document_date' => '2026-04-05',
            'category' => 'Software e abbonamenti cloud', 'currency' => 'EUR',
            'amount_net' => 90, 'amount_vat' => 19.80, 'amount_gross' => 109.80,
            'is_credit_note' => true,
        ]])
        ->call('crea')
        ->assertHasNoErrors();

    $note = PassiveInvoice::first();
    expect($note->type)->toBe(PassiveInvoice::TYPE_CREDIT_NOTE);
    expect($note->isCreditNote())->toBeTrue();
});
