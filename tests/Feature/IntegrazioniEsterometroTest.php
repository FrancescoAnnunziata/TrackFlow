<?php

use App\Filament\Pages\FattureEstere;
use App\Models\FicCredential;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Fic\IntegrationDocument;
use App\Services\Reporting\FattureEstereBuilder;
use App\Services\Reporting\RegistroAcquistiBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** XML ridotto, sulla falsariga di quello che lo SdI consegna per un TD17. */
function xmlFattura(string $tipo, ?string $collegata = '4701794', ?string $data = '2026-06-19'): string
{
    $collegate = $collegata !== null
        ? "<DatiFattureCollegate><IdDocumento>{$collegata}</IdDocumento><Data>{$data}</Data></DatiFattureCollegate>"
        : '';

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <ns3:FatturaElettronica versione="FPR12" xmlns:ns3="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
      <FatturaElettronicaBody><DatiGenerali>
        <DatiGeneraliDocumento><TipoDocumento>{$tipo}</TipoDocumento><Data>2026-10-16</Data><Numero>16/UE</Numero></DatiGeneraliDocumento>
        {$collegate}
      </DatiGenerali></FatturaElettronicaBody>
    </ns3:FatturaElettronica>
    XML;
}

function fakeIntegrazioneFic(string $xml, array $overrides = []): void
{
    config()->set('services.fic', [
        'client_id' => 'x', 'client_secret' => 'y', 'redirect' => 'z',
        'base_url' => 'https://api-v2.fattureincloud.it', 'scopes' => 's',
    ]);
    FicCredential::create([
        'access_token' => 'a/valid', 'refresh_token' => 'r/valid',
        'expires_at' => now()->addHour(), 'company_id' => '123',
    ]);

    $detail = array_merge([
        'id' => 443690289,
        'type' => 'expense',
        'description' => 'Documento integrativo Art.17, c.2 DPR 633/72 - Servizi',
        'invoice_number' => '16/UE',
        'e_invoice' => true,
        'date' => '2026-10-16',
        'amount_net' => 492.87, 'amount_vat' => 108.43, 'amount_gross' => 601.30,
        'category' => 'Servizi aziendali',
        'entity' => ['name' => 'SITEGROUND SPAIN S.L.', 'vat_number' => 'B87194171', 'address_province' => 'EE', 'country' => 'Spagna'],
        'items_list' => [['name' => 'Documento integrativo Art.17, c.2 DPR 633/72 - Servizi', 'qty' => 1, 'net_price' => 492.87, 'vat' => ['value' => 22]]],
        // Come arriva da FiC: con le & codificate.
        'attachment_url' => 'https://fattureincloud.s3.eu-west-1.amazonaws.com/x/IT04704320987_0000G.xml?a=1&amp;b=2',
    ], $overrides);

    Http::fake([
        'fattureincloud.s3.*' => Http::response($xml),
        '*/received_documents/*' => Http::response(['data' => $detail]),
        '*/received_documents*' => fn ($request) => str_contains($request->url(), 'page=1')
            ? Http::response(['data' => [['id' => $detail['id']]]])
            : Http::response(['data' => []]),
    ]);
}

function fatturaSiteground(string $number = '4701794', string $date = '2026-06-19', float $net = 492.87): PassiveInvoice
{
    $supplier = Supplier::firstOrCreate(['name' => 'SiteGround Spain S.L.'], ['vat_number' => 'ESB87194171', 'country_iso' => 'ES']);

    return PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => $number, 'type' => 'expense',
        'document_date' => $date, 'amount_net' => $net, 'amount_vat' => 0, 'amount_gross' => $net,
        'currency' => 'EUR', 'category' => 'Software e abbonamenti cloud',
        'payment_status' => PassiveInvoice::STATUS_PAID, 'imported' => false,
        'attachment' => "passive-attachments/sg-{$number}.pdf",
    ]);
}

function importa(): void
{
    test()->artisan('fic:import-passive-invoices', ['--types' => 'expense', '--create-suppliers' => true])->assertSuccessful();
}

it('reads type and linked invoice from the XML', function () {
    expect(IntegrationDocument::parse(xmlFattura('TD17')))
        ->toBe(['type' => 'TD17', 'number' => '4701794', 'date' => '2026-06-19']);
    expect(IntegrationDocument::parse(xmlFattura('TD01', null)))->toBeNull();
    expect(IntegrationDocument::parse('non è xml'))->toBeNull();
});

it('links the integration to the foreign invoice instead of adding a second cost', function () {
    User::factory()->admin()->create();
    $originale = fatturaSiteground();
    fakeIntegrazioneFic(xmlFattura('TD17'));

    importa();

    // Non c'è una fattura passiva in più…
    expect(PassiveInvoice::count())->toBe(1);
    // …ma l'integrazione è registrata e attaccata all'originale.
    $integrazione = PassiveInvoice::conIntegrazioni()->where('fic_document_id', 443690289)->sole();
    expect($integrazione->integration_type)->toBe('TD17');
    expect($integrazione->integrates_id)->toBe($originale->id);
    expect($integrazione->supplier_id)->toBe($originale->supplier_id);
    expect($originale->integrations->pluck('number')->all())->toBe(['16/UE']);

    // Nel registro acquisti il costo c'è una volta sola.
    $registro = app(RegistroAcquistiBuilder::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')->endOfDay());
    // (filtrato sui nostri numeri: altri test lasciano spese nel database)
    $nostre = collect($registro['rows'])->where('kind', 'data')
        ->filter(fn (array $r): bool => in_array($r['cells'][9], ['4701794', '16/UE'], true));
    expect($nostre->pluck('cells.9')->all())->toBe(['4701794']);

    // L'elenco per l'esterometro dice che è già integrata.
    $estere = app(FattureEstereBuilder::class)->build(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')->endOfDay());
    expect($estere['rows'][0][12])->toBe('16/UE del 16/10/2026');
});

it('stays an integration on the next import, without duplicating', function () {
    User::factory()->admin()->create();
    fatturaSiteground();
    fakeIntegrazioneFic(xmlFattura('TD17'));

    importa();
    importa();

    expect(PassiveInvoice::conIntegrazioni()->where('fic_document_id', 443690289)->count())->toBe(1);
    expect(PassiveInvoice::count())->toBe(1);
});

it('uses the date when the same number matches more invoices', function () {
    User::factory()->admin()->create();
    fatturaSiteground('4535410', '2026-03-19', 12.99);
    $giusta = fatturaSiteground('4535410', '2026-04-04', 12.99);
    fakeIntegrazioneFic(xmlFattura('TD17', '4535410', '2026-04-04'), ['amount_net' => 12.99, 'amount_vat' => 2.86, 'amount_gross' => 15.85]);

    importa();

    expect(PassiveInvoice::conIntegrazioni()->where('fic_document_id', 443690289)->value('integrates_id'))->toBe($giusta->id);
});

it('waits for the foreign invoice and links when it is uploaded', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    fakeIntegrazioneFic(xmlFattura('TD17'));

    importa();

    $integrazione = PassiveInvoice::conIntegrazioni()->where('fic_document_id', 443690289)->sole();
    expect($integrazione->integrates_id)->toBeNull();
    expect(app(FattureEstereBuilder::class)->unlinkedIntegrations())->toHaveCount(1);
    // Anche in attesa, non è un costo.
    expect(PassiveInvoice::count())->toBe(0);

    $this->actingAs($admin);
    Livewire\Livewire::test(FattureEstere::class)
        ->set('data.rows', [[
            'attachment' => 'passive-attachments/sg.pdf',
            'supplier_name' => 'SiteGround Spain S.L.', 'supplier_vat' => 'ESB87194171', 'supplier_country_iso' => 'ES',
            'number' => '4701794', 'document_date' => '2026-06-19',
            'category' => 'Software e abbonamenti cloud', 'currency' => 'EUR',
            'amount_net' => 492.87, 'amount_vat' => 0, 'amount_gross' => 492.87,
        ]])
        ->call('crea')
        ->assertHasNoErrors();

    expect($integrazione->fresh()->integrates_id)->toBe(PassiveInvoice::sole()->id);
    expect(app(FattureEstereBuilder::class)->unlinkedIntegrations())->toBe([]);
});

it('keeps an integration out of costs even when the XML cannot be read', function () {
    User::factory()->admin()->create();
    fakeIntegrazioneFic('');

    importa();

    expect(PassiveInvoice::count())->toBe(0);
    expect(PassiveInvoice::conIntegrazioni()->value('integration_type'))->toBe('INT');
});

it('still imports an ordinary foreign e-invoice as a passive invoice', function () {
    User::factory()->admin()->create();
    fakeIntegrazioneFic(xmlFattura('TD01', null), [
        'description' => 'Servizi cloud', 'invoice_number' => 'EUINIT26-1',
        'items_list' => [['name' => 'AWS', 'qty' => 1, 'net_price' => 80, 'vat' => ['value' => 22]]],
    ]);

    importa();

    expect(PassiveInvoice::where('fic_document_id', 443690289)->value('integration_type'))->toBeNull();
});
