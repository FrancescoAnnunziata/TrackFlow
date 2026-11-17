<?php

use App\Assistant\AssistantRunner;
use App\Assistant\AssistantTurn;
use App\Assistant\Contracts\ChatClient;
use App\Assistant\Tools\ReadReimbursementAttachmentTool;
use App\Assistant\Tools\ReadReimbursementsTool;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

it('lists the attachments of each reimbursement, numbered', function () {
    Reimbursement::factory()->create([
        'notes' => 'Rimborso spese Giugno 2026',
        'attachments' => ['reimbursement-attachments/a.pdf', 'reimbursement-attachments/b.JPG'],
    ]);
    Reimbursement::factory()->create(['notes' => 'Rimborso spese Luglio 2026', 'attachments' => null]);

    $out = app(ReadReimbursementsTool::class)->run([])->content;

    expect($out)->toContain('allegati: 1) pdf, 2) jpg')->toContain('nessun allegato');
});

it('hands images and PDFs to the model as they are', function () {
    Storage::disk('public')->put('reimbursement-attachments/scontrino.png', 'PNGDATA');
    Storage::disk('public')->put('reimbursement-attachments/fattura.pdf', '%PDF-1.4 finto');
    $r = Reimbursement::factory()->create([
        'attachments' => ['reimbursement-attachments/scontrino.png', 'reimbursement-attachments/fattura.pdf'],
    ]);

    $result = app(ReadReimbursementAttachmentTool::class)->run(['reimbursement_id' => $r->id]);

    $tipi = collect($result->blocks)->pluck('type')->all();
    expect($result->isError)->toBeFalse()
        ->and($tipi)->toContain('image')->toContain('document');

    $immagine = collect($result->blocks)->firstWhere('type', 'image');
    expect($immagine['source'])->toBe(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('PNGDATA')]);
    expect(collect($result->blocks)->firstWhere('type', 'document')['source']['media_type'])->toBe('application/pdf');
});

it('opens a single attachment by number', function () {
    Storage::disk('public')->put('reimbursement-attachments/a.png', 'A');
    Storage::disk('public')->put('reimbursement-attachments/b.pdf', 'B');
    $r = Reimbursement::factory()->create([
        'attachments' => ['reimbursement-attachments/a.png', 'reimbursement-attachments/b.pdf'],
    ]);

    $result = app(ReadReimbursementAttachmentTool::class)->run(['reimbursement_id' => $r->id, 'attachment' => 2]);

    expect(collect($result->blocks)->pluck('type')->all())->toContain('document')->not->toContain('image');

    $fuori = app(ReadReimbursementAttachmentTool::class)->run(['reimbursement_id' => $r->id, 'attachment' => 3]);
    expect($fuori->isError)->toBeTrue();
});

it('says plainly when there is nothing to read, instead of failing', function () {
    Storage::disk('public')->put('reimbursement-attachments/foto.heic', 'HEIC');
    $r = Reimbursement::factory()->create([
        'attachments' => ['reimbursement-attachments/foto.heic', 'reimbursement-attachments/sparito.pdf'],
    ]);
    $vuoto = Reimbursement::factory()->create(['attachments' => null]);

    $result = app(ReadReimbursementAttachmentTool::class)->run(['reimbursement_id' => $r->id]);

    expect($result->content)
        ->toContain('Allegato 1 (heic): formato che non riesco a leggere')
        ->toContain('Allegato 2 (pdf): il file non c\'è sul server');
    expect(collect($result->blocks)->whereIn('type', ['image', 'document']))->toBeEmpty();

    expect(app(ReadReimbursementAttachmentTool::class)->run(['reimbursement_id' => $vuoto->id])->content)
        ->toContain('non ha allegati');
});

it('passes the file blocks inside the tool_result sent to the model', function () {
    Storage::disk('public')->put('reimbursement-attachments/s.jpg', 'JPG');
    $r = Reimbursement::factory()->create(['attachments' => ['reimbursement-attachments/s.jpg']]);

    $fake = new class($r->id) implements ChatClient
    {
        public array $calls = [];

        public function __construct(private int $id) {}

        public function converse(string $systemStatic, string $systemContext, array $messages, array $tools, string $model): AssistantTurn
        {
            $this->calls[] = ['messages' => $messages, 'tools' => $tools];

            if (count($this->calls) === 1) {
                $use = ['id' => 'tu_1', 'name' => 'read_reimbursement_attachment', 'input' => ['reimbursement_id' => $this->id]];

                return new AssistantTurn([['type' => 'tool_use'] + $use], [$use], '', 'tool_use');
            }

            return new AssistantTurn([['type' => 'text', 'text' => 'visto']], [], 'visto', 'end_turn');
        }
    };
    app()->instance(ChatClient::class, $fake);

    $user = User::factory()->admin()->create();
    $thread = AssistantThread::create(['user_id' => $user->id, 'model' => 'claude-opus-5']);
    AssistantMessage::create(['assistant_thread_id' => $thread->id, 'role' => 'user', 'content' => 'cosa c\'è nel rimborso?', 'status' => 'done']);

    app(AssistantRunner::class)->run($thread->fresh());

    expect(collect($fake->calls[0]['tools'])->pluck('name'))->toContain('read_reimbursement_attachment');

    $risultato = collect(end($fake->calls)['messages'])->last()['content'][0];
    expect($risultato['type'])->toBe('tool_result')
        ->and($risultato['content'])->toBeArray()
        ->and(collect($risultato['content'])->pluck('type')->all())->toContain('image');
});
