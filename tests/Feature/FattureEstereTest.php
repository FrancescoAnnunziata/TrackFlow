<?php

use App\Filament\Pages\FattureEstere;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\ForeignInvoiceExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
            'description' => 'Rinnovo dominio',
        ];
    });
    app()->instance(ForeignInvoiceExtractor::class, $mock);
}

it('reads the uploaded PDF (via getState) and fills the review rows', function () {
    Storage::fake('public');
    mockExtractor();
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('sg.pdf', '%PDF-1.4 contenuto finto')])
        ->call('estrai')
        ->assertHasNoErrors()
        ->assertSet('data.rows', function (array $rows): bool {
            $first = collect($rows)->first();

            return count($rows) === 1
                && $first['supplier_name'] === 'SiteGround Spain S.L.'
                && $first['number'] === '4535410'
                && $first['currency'] === 'EUR';
        });
});

it('keeps the uploaded PDF on disk after creating the invoice (not deleted)', function () {
    Storage::fake('public');
    mockExtractor();
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire\Livewire::test(FattureEstere::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('sg.pdf', '%PDF-1.4 reale')])
        ->call('estrai');

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
    expect(Supplier::where('name', 'SiteGround Spain S.L.')->exists())->toBeTrue();
});
