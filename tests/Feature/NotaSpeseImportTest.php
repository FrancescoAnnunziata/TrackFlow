<?php

use App\Enums\ReimbursementType;
use App\Filament\Resources\Reimbursements\Pages\ListReimbursements;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\Reimbursements\NotaSpeseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Le note spese chilometriche arrivano come Excel «RIMB. <mese> <anno>»: chi
 * importa (Paola, per conto di Giorgio) carica i file e TrackFlow crea solo i
 * rimborsi che mancano. I fixture sono note vere.
 */
function notaSpese(string $mese): array
{
    $nome = "RIMB. {$mese} 2026.xlsx";

    return ['nome' => $nome, 'percorso' => base_path("tests/fixtures/note-spese/{$nome}"), 'allegato' => "reimbursement-attachments/{$nome}"];
}

beforeEach(function () {
    // Alcuni test dei rimborsi non ripuliscono il database: si contano solo i
    // rimborsi degli utenti creati qui.
    $this->preesistenti = User::pluck('id');
    $this->giorgio = User::factory()->create(['name' => 'Giorgio', 'surname' => 'Giotto', 'role' => 'admin']);
});

it('crea il rimborso del mese con il costo dei km, intestato a chi ha fatto la nota', function () {
    $esito = app(NotaSpeseImporter::class)->importa([notaSpese('Ago')], $this->giorgio);

    expect($esito['creati'])->toHaveCount(1)->and($esito['errori'])->toBe([]);

    $rimborso = Reimbursement::where('user_id', $this->giorgio->id)->sole();
    expect($rimborso->user_id)->toBe($this->giorgio->id)
        ->and($rimborso->type)->toBe(ReimbursementType::Travel)
        ->and($rimborso->date->toDateString())->toBe('2026-08-31')
        ->and((float) $rimborso->amount)->toBe(251.90)
        ->and($rimborso->notes)->toBe('Rimborso spese Agosto 2026')
        ->and($rimborso->attachments)->toBe(['reimbursement-attachments/RIMB. Ago 2026.xlsx']);

    $km = $rimborso->costi()->sole();
    expect($km->category)->toBe('Trasferte')
        ->and((float) $km->amount)->toBe(251.90)
        ->and($km->description)->toBe('Rimborso km Agosto 2026 (480 km × 0,5248 €/km)');
});

it('mette le altre spese in costi a parte, da controllare', function () {
    $esito = app(NotaSpeseImporter::class)->importa([notaSpese('Apr')], $this->giorgio);

    $rimborso = Reimbursement::where('user_id', $this->giorgio->id)->sole();
    expect((float) $rimborso->amount)->toBe(1653.24)
        ->and($esito['daControllare'])->toBe(1)
        ->and(round((float) $rimborso->costi()->sum('amount'), 2))->toBe(1653.24);

    $altra = $rimborso->costi()->where('category', NotaSpeseImporter::CONTO_ALTRE_SPESE)->sole();
    expect((float) $altra->amount)->toBe(109.80)
        ->and($altra->description)->toContain('claude.ai')
        ->and($altra->date->toDateString())->toBe('2026-04-05');
});

it('non crea due volte lo stesso mese', function () {
    $importer = app(NotaSpeseImporter::class);
    $importer->importa([notaSpese('Ago')], $this->giorgio);

    // Lo stesso file due volte nello stesso caricamento, e poi di nuovo.
    $esito = $importer->importa([notaSpese('Ago'), notaSpese('Ago')], $this->giorgio);

    expect(Reimbursement::whereIn('user_id', User::pluck('id')->diff($this->preesistenti))->count())->toBe(1)
        ->and($esito['creati'])->toBe([])
        ->and($esito['saltati'])->toHaveCount(2);
});

it('rifiuta un file il cui nome dice un mese e l\'intestazione un altro', function () {
    // Nota vera: il file di maggio ha ancora «Aprile 2026» nell'intestazione.
    $esito = app(NotaSpeseImporter::class)->importa([notaSpese('Mag')], $this->giorgio);

    expect(Reimbursement::whereIn('user_id', User::pluck('id')->diff($this->preesistenti))->count())->toBe(0)
        ->and($esito['errori'][0])->toContain('Maggio 2026')->toContain('Aprile 2026');
});

it('rifiuta una nota di un\'altra persona', function () {
    $paola = User::factory()->create(['name' => 'Paola', 'surname' => 'Rossi', 'role' => 'admin']);

    $esito = app(NotaSpeseImporter::class)->importa([notaSpese('Ago')], $paola);

    expect(Reimbursement::whereIn('user_id', User::pluck('id')->diff($this->preesistenti))->count())->toBe(0)
        ->and($esito['errori'][0])->toContain('Giorgio Giotto');
});

it('il pulsante di importazione è solo per gli admin', function () {
    $member = User::factory()->create(['role' => 'member']);

    Livewire::actingAs($member)->test(ListReimbursements::class)->assertActionHidden('importaNoteSpese');
    Livewire::actingAs($this->giorgio)->test(ListReimbursements::class)->assertActionVisible('importaNoteSpese');
});

it('dal pulsante: crea i mesi nuovi, tiene l\'Excel come allegato e butta i file scartati', function () {
    Storage::fake('public');
    $file = fn (string $mese): UploadedFile => UploadedFile::fake()->createWithContent(
        "RIMB. {$mese} 2026.xlsx", file_get_contents(base_path("tests/fixtures/note-spese/RIMB. {$mese} 2026.xlsx")),
    );

    Livewire::actingAs($this->giorgio)
        ->test(ListReimbursements::class)
        ->callAction('importaNoteSpese', data: [
            'user_id' => $this->giorgio->id,
            'files' => [$file('Ago'), $file('Mag')],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Rimborsi creati: 1');

    $rimborso = Reimbursement::where('user_id', $this->giorgio->id)->sole();
    expect($rimborso->notes)->toBe('Rimborso spese Agosto 2026');
    Storage::disk('public')->assertExists($rimborso->attachments[0]);
    expect(Storage::disk('public')->allFiles('reimbursement-attachments'))->toHaveCount(1);
});
