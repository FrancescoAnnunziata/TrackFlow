<?php

use App\Assistant\Tools\ProposeReconciliationTool;
use App\Exceptions\EmittentiDiversiException;
use App\Filament\Resources\Emittenti\EmittenteResource;
use App\Filament\Resources\Emittenti\Pages\EditEmittente;
use App\Filament\Resources\Emittenti\Pages\ListEmittenti;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Emittente;
use App\Models\EmittenteRegime;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\MovementReconciler;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reporting\FinancialOverviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fatturaDi(Emittente $emittente, string $data, float $netto, array $extra = []): Invoice
{
    $client = Client::create(['name' => 'Cliente '.$emittente->chiave, 'emittente_id' => $emittente->id]);
    $invoice = Invoice::create($extra + [
        'user_id' => User::factory()->admin()->create()->id, 'client_id' => $client->id,
        'number' => '1/'.substr($data, 0, 4), 'issue_date' => $data, 'period_from' => $data, 'period_to' => $data,
        'vat_rate' => 0, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Voce', 'qty' => 1, 'net_price' => $netto, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    return $invoice->load('items');
}

it('crea la società e la P.IVA personale con i loro regimi', function () {
    expect(emittenteSocieta()->regimeNel(2026))->toBe(Emittente::REGIME_ORDINARIO)
        ->and(emittentePiva()->isForfettarioNel(2024))->toBeTrue()
        ->and(emittentePiva()->isForfettarioNel(2025))->toBeFalse()
        ->and(emittentePiva()->isForfettarioNel(2026))->toBeTrue()
        ->and(emittenteSocieta()->inviaConFic())->toBeTrue()
        ->and(emittentePiva()->inviaConFic())->toBeFalse();
});

it('copia sulla fattura emittente, canale e regime dell\'anno di emissione', function () {
    $del2025 = fatturaDi(emittentePiva(), '2025-07-01', 2000, ['vat_rate' => 22]);
    $del2026 = fatturaDi(emittentePiva(), '2026-03-01', 500);

    expect($del2025->emittente_id)->toBe(emittentePiva()->id)
        ->and($del2025->regime_fiscale)->toBe('RF01')
        ->and($del2025->canale)->toBe(Emittente::CANALE_FISCOZEN)
        ->and($del2026->regime_fiscale)->toBe('RF19');

    // Una volta emessa, la fattura non cambia se cambia l'emittente.
    emittentePiva()->update(['canale_invio' => Emittente::CANALE_ACUBE]);
    $del2026->update(['notes' => 'x']);

    expect($del2026->fresh()->canale)->toBe(Emittente::CANALE_FISCOZEN);
});

it('non mostra il quadro forfettario in un anno di regime ordinario', function () {
    fatturaDi(emittentePiva(), '2025-07-01', 2000, ['vat_rate' => 22, 'paid_at' => '2025-07-20']);

    $f = app(FinancialOverviewBuilder::class)->forfettario(2025);

    expect($f['regime_forfettario'])->toBeFalse()
        ->and($f['incassato_anno'])->toBe(0.0);
});

it('tiene i conti della P.IVA fuori dalla cassa di G8 Labs', function () {
    BankAccount::create(['name' => 'G8', 'bank_key' => 'inbank', 'opening_balance' => 1000]);
    BankAccount::create(['name' => 'Conto P.IVA', 'bank_key' => 'generic', 'opening_balance' => 500, 'emittente_id' => emittentePiva()->id]);

    $snap = app(FinancialOverviewBuilder::class)->g8labsSnapshot();

    expect($snap['liquidita'])->toBe(1000.0)
        ->and(array_column($snap['accounts'], 'name'))->toBe(['G8']);
});

it('propone in riconciliazione solo fatture dello stesso titolare del conto', function () {
    $g8 = fatturaDi(emittenteSocieta(), '2026-06-10', 1000);
    $piva = fatturaDi(emittentePiva(), '2026-06-10', 1000);
    $conto = BankAccount::create(['name' => 'Conto P.IVA', 'bank_key' => 'generic', 'emittente_id' => emittentePiva()->id]);
    $mov = BankTransaction::create(['bank_account_id' => $conto->id, 'booked_at' => '2026-06-15', 'amount' => 1000, 'description' => 'bonifico', 'dedup_hash' => 'p1']);

    $ids = app(MatchSuggestionService::class)->candidatePool($mov)->map(fn (array $c) => $c['model']->id)->all();

    expect($ids)->toContain($piva->id)->not->toContain($g8->id);
});

it('lascia modificare gli emittenti solo agli admin, senza crearne o cancellarne', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListEmittenti::class)->assertSuccessful()->assertCanSeeTableRecords(Emittente::all());
    Livewire::test(EditEmittente::class, ['record' => emittentePiva()->getRouteKey()])
        ->fillForm(['iban' => 'IT60X0542811101000000123456'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(emittentePiva()->iban)->toBe('IT60X0542811101000000123456')
        ->and(EmittenteResource::canCreate())->toBeFalse()
        ->and(User::factory()->make(['role' => 'member'])->isAdmin())->toBeFalse()
        ->and(EmittenteRegime::where('emittente_id', emittentePiva()->id)->value('anno'))->toBe(2025);
});

it('rifiuta una riconciliazione fra emittenti diversi, da qualunque parte arrivi', function () {
    $fattura = fatturaDi(emittentePiva(), '2026-01-10', 700);
    $contoG8 = BankAccount::create(['name' => 'Conto società', 'bank_key' => 'generic']);
    $mov = BankTransaction::create(['bank_account_id' => $contoG8->id, 'booked_at' => '2026-01-20', 'amount' => 700, 'description' => 'Versamento', 'dedup_hash' => 'x1']);

    // Servizio (interfaccia e riconciliazione automatica).
    expect(fn () => app(ReconciliationService::class)->attach($mov, $fattura, 700))
        ->toThrow(EmittentiDiversiException::class);

    // Popup dei movimenti e conferma delle proposte dell'assistente.
    $esito = app(MovementReconciler::class)->reconcile($mov, [['type' => 'invoice', 'id' => $fattura->id]]);
    expect($esito['attached'])->toBe(0)->and($esito['skipped'])->toHaveCount(1);

    // Proposta dell'assistente.
    $proposta = app(ProposeReconciliationTool::class)->run(['movement_id' => $mov->id, 'targets' => [['type' => 'invoice', 'id' => $fattura->id]]]);
    expect($proposta->isError)->toBeTrue()
        ->and($mov->fresh()->reconciliations()->count())->toBe(0);
});

it('lascia pagata una fattura con data di incasso quando si toglie la riconciliazione', function () {
    $fattura = fatturaDi(emittentePiva(), '2026-02-10', 500, ['paid_at' => '2026-03-01']);
    $conto = BankAccount::create(['name' => 'Conto P.IVA', 'bank_key' => 'generic', 'emittente_id' => emittentePiva()->id]);
    $mov = BankTransaction::create(['bank_account_id' => $conto->id, 'booked_at' => '2026-03-01', 'amount' => 500, 'description' => 'bonifico', 'dedup_hash' => 'x2']);

    $rec = app(ReconciliationService::class)->attach($mov, $fattura, 500);
    app(ReconciliationService::class)->detach($rec);

    expect($fattura->fresh()->status)->toBe('paid');
});
