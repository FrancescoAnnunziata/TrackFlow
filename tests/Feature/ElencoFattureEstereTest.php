<?php

use App\Assistant\Tools\ReadPassiveInvoicesTool;
use App\Filament\Pages\ElencoFattureEstere;
use App\Filament\Pages\FattureEstere;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reporting\DocumentReference;
use App\Services\Reporting\FattureEstereBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function passivaEstera(Supplier $supplier, string $date, array $extra = []): PassiveInvoice
{
    return PassiveInvoice::create([
        'supplier_id' => $supplier->id,
        'number' => 'N-'.uniqid(),
        'type' => 'expense',
        'document_date' => $date,
        'amount_net' => 100,
        'amount_vat' => 0,
        'amount_gross' => 100,
        'currency' => 'EUR',
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
        'imported' => false,
        'attachment' => 'passive-attachments/'.uniqid().'.pdf',
        ...$extra,
    ]);
}

function righe(string $from, string $to): array
{
    return app(FattureEstereBuilder::class)->build(
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    )['rows'];
}

it('lists foreign suppliers only, credit notes included and negative', function () {
    Storage::fake('public');
    $us = Supplier::create(['name' => 'Tailscale US Inc.', 'country_iso' => 'US']);
    $fr = Supplier::create(['name' => 'Meilisearch', 'vat_number' => 'FR88844156364', 'country_iso' => 'FR']);
    $it = Supplier::create(['name' => 'Microsoft Srl', 'vat_number' => 'IT08106710158', 'country_iso' => 'IT']);

    passivaEstera($us, '2026-10-14', ['original_currency' => 'USD', 'original_amount' => 8.30, 'amount_gross' => 7.17, 'amount_net' => 7.17]);
    passivaEstera($fr, '2026-10-05');
    passivaEstera($fr, '2026-10-20', ['type' => PassiveInvoice::TYPE_CREDIT_NOTE, 'amount_gross' => 10, 'amount_net' => 10]);
    passivaEstera($it, '2026-10-27');

    $rows = righe('2026-10-01', '2026-10-31');

    expect($rows)->toHaveCount(3);
    expect(collect($rows)->pluck(3)->all())->not->toContain('Microsoft Srl');

    [$meili, $tailscale, $nota] = $rows;
    expect($meili[4])->toBe('Francia')->and($meili[5])->toBe('UE')->and($meili[6])->toBe('FR88844156364');
    expect($tailscale[4])->toBe('Stati Uniti')->and($tailscale[5])->toBe('Extra-UE');
    expect($tailscale[7])->toBe('USD')->and($tailscale[8])->toBe(8.3)->and($tailscale[11])->toBe(7.17);
    expect($tailscale[13])->toContain('/storage/passive-attachments/')->toEndWith('.pdf');
    expect($nota[2])->toBe('Nota di credito')->and($nota[11])->toBe(-10.0);
});

it('respects any period, not just a month', function () {
    $us = Supplier::create(['name' => 'Anthropic, PBC', 'country_iso' => 'US']);
    passivaEstera($us, '2026-05-04');
    passivaEstera($us, '2026-06-04');
    passivaEstera($us, '2026-09-08');
    passivaEstera($us, '2026-11-08');

    expect(righe('2026-06-01', '2026-10-31'))->toHaveCount(2);
    expect(righe('2026-09-01', '2026-09-30'))->toHaveCount(1);
});

it('marks a passive invoice as foreign by supplier country, not by how it was loaded', function () {
    $it = Supplier::create(['name' => 'Studio Arti', 'country_iso' => 'IT']);
    $es = Supplier::create(['name' => 'SiteGround Spain S.L.', 'country_iso' => 'ES']);

    expect(DocumentReference::isForeignPassive(passivaEstera($it, '2026-10-01')))->toBeFalse();
    expect(DocumentReference::isForeignPassive(passivaEstera($es, '2026-10-01')))->toBeTrue();
});

it('keeps the readable country in step with the ISO code', function () {
    $supplier = Supplier::create(['name' => 'Geekom', 'country_iso' => 'HK']);
    expect($supplier->country)->toBe('Hong Kong');

    $supplier->update(['country_iso' => 'IE']);
    expect($supplier->fresh()->country)->toBe('Irlanda');
});

it('saves the reviewed country on new and existing suppliers', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->admin()->create());
    // Fornitore creato prima che il campo esistesse: rimasto al default Italia.
    $vecchio = Supplier::create(['name' => 'Zapier Inc.', 'vat_number' => 'EU372079631']);
    expect($vecchio->fresh()->country_iso)->toBe('IT');

    $riga = fn (string $name, ?string $vat, string $iso, string $number): array => [
        'attachment' => "passive-attachments/{$number}.pdf",
        'supplier_name' => $name, 'supplier_vat' => $vat, 'supplier_country_iso' => $iso,
        'number' => $number, 'document_date' => '2026-10-10',
        'category' => 'Software e abbonamenti cloud', 'currency' => 'EUR',
        'amount_net' => 10, 'amount_vat' => 0, 'amount_gross' => 10,
    ];

    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.rows', [
            $riga('Zapier Inc.', 'EU372079631', 'US', 'Z-1'),
            $riga('Wispr AI, Inc.', null, 'US', 'W-1'),
        ])
        ->call('crea')
        ->assertHasNoErrors();

    expect($vecchio->fresh()->country_iso)->toBe('US');
    expect(Supplier::where('name', 'Wispr AI, Inc.')->value('country'))->toBe('Stati Uniti');
});

it('exports the period chosen on the page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $us = Supplier::create(['name' => 'Tailscale US Inc.', 'country_iso' => 'US']);
    passivaEstera($us, '2026-07-14');

    Livewire\Livewire::test(ElencoFattureEstere::class)
        ->set('from', '2026-06-01')
        ->set('until', '2026-11-30')
        ->assertSee('Tailscale US Inc.')
        ->call('export', 'xlsx')
        ->assertFileDownloaded('fatture-estere-2026-06-01_2026-11-30.xlsx');
});

it('is admin only', function () {
    $this->actingAs(User::factory()->create());

    expect(ElencoFattureEstere::canAccess())->toBeFalse();
});

it('leaves out what comes from Fatture in Cloud, such as the accountant integrations', function () {
    $fr = Supplier::create(['name' => 'Meilisearch', 'country_iso' => 'FR']);
    $originale = passivaEstera($fr, '2026-10-05');
    // Integrazione TD17 emessa dal commercialista: torna dallo SdI su FiC col
    // fornitore estero e l'IVA, ma non è una fattura estera da registrare.
    $integrazione = passivaEstera($fr, '2026-10-16', [
        'number' => '4/UE', 'fic_document_id' => 123456, 'attachment' => null, 'amount_vat' => 5.71,
    ]);

    expect(collect(righe('2026-10-01', '2026-10-31'))->pluck(1)->all())->toBe([$originale->number]);
    expect(DocumentReference::isForeignPassive($integrazione))->toBeFalse();
});

it('lets the assistant read the same foreign invoices as the page', function () {
    $us = Supplier::create(['name' => 'Tailscale US Inc.', 'country_iso' => 'US']);
    $it = Supplier::create(['name' => 'Microsoft Srl', 'country_iso' => 'IT']);
    passivaEstera($us, '2026-10-14', ['number' => 'PQ3-5']);
    passivaEstera($it, '2026-10-27', ['number' => 'IT-TI26']);

    $result = app(ReadPassiveInvoicesTool::class)->run(['only_foreign' => true]);
    $text = json_encode($result);

    expect($text)->toContain('PQ3-5')->toContain('estera, Stati Uniti')->not->toContain('IT-TI26');
});
