<?php

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\Reimbursement;
use App\Models\TravelRate;
use App\Models\User;
use App\Services\ReimbursementNoteExporter;
use App\Services\TravelReimbursementService;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;

function travellingMember(): User
{
    return User::factory()->create([
        'role' => 'member',
        'km_rate' => 0.5248,
        'vehicle_plate' => 'FS760HS',
        'vehicle_model' => 'Jeep Compass',
    ]);
}

it('computes the travel reimbursement as km × per-km rate', function () {
    $user = travellingMember();
    $rate = TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'FIORAVANTI', 'km' => 350]);

    $reimbursement = app(TravelReimbursementService::class)
        ->generate($user, $rate, Carbon::create(2026, 4, 3));

    expect($reimbursement->type)->toBe(ReimbursementType::Travel);
    expect($reimbursement->status)->toBe(ReimbursementStatus::Pending);
    expect((float) $reimbursement->km)->toBe(350.0);
    expect((float) $reimbursement->amount)->toBe(183.68); // 350 * 0.5248
    expect($reimbursement->travel_type)->toBe('FIORAVANTI');
});

it('is idempotent per day: regenerating updates the same record and keeps a manual status', function () {
    $user = travellingMember();
    $day = Carbon::create(2026, 4, 5);
    $rateA = TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'ALSEA', 'km' => 240]);
    $rateB = TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'FEDESPEDI', 'km' => 100]);
    $service = app(TravelReimbursementService::class);

    $first = $service->generate($user, $rateA, $day);
    $first->update(['status' => ReimbursementStatus::Paid]);

    $second = $service->generate($user, $rateB, $day);

    expect($second->id)->toBe($first->id);
    expect(Reimbursement::where('user_id', $user->id)->where('type', ReimbursementType::Travel)->count())->toBe(1);
    expect($second->travel_type)->toBe('FEDESPEDI');
    expect((float) $second->km)->toBe(100.0);
    expect($second->status)->toBe(ReimbursementStatus::Paid); // stato manuale non resettato
});

it('exports a monthly note with trips, other expenses and totals', function () {
    $user = travellingMember();
    $rate = TravelRate::factory()->create([
        'user_id' => $user->id, 'tipo' => 'ALSEA', 'km' => 240,
        'from_location' => 'Via Pisa 74', 'to_location' => 'Via Cornalia 19', 'purpose' => 'Trasferta Alsea',
    ]);
    app(TravelReimbursementService::class)->generate($user, $rate, Carbon::create(2026, 4, 4));
    Reimbursement::create([
        'user_id' => $user->id, 'type' => ReimbursementType::PersonalCard,
        'status' => ReimbursementStatus::Pending, 'date' => '2026-04-10', 'amount' => 42.50, 'notes' => 'Pranzo',
    ]);

    $path = app(ReimbursementNoteExporter::class)->export($user, 2026, 4, null);

    $flat = [];
    $reader = new Reader;
    $reader->open($path);
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCells() as $cell) {
                $v = $cell->getValue();
                if ($v !== '' && $v !== null) {
                    $flat[] = is_scalar($v) ? (string) $v : '';
                }
            }
        }
    }
    $reader->close();
    @unlink($path);

    expect($flat)->toContain('NOTA SPESE RIMBORSI CHILOMETRICI');
    expect($flat)->toContain('ALSEA');
    expect($flat)->toContain('240');          // km trasferta
    expect($flat)->toContain('42.5');         // altre spese
    expect($flat)->toContain('125.95');       // indennità km = 240 * 0.5248
    expect($flat)->toContain('168.45');       // totale = 125.95 + 42.5
});

it('lets an internal user download the note and forbids clients', function () {
    $member = travellingMember();
    $client = User::factory()->create(['role' => 'client']);

    $this->actingAs($member)
        ->get(route('reimbursements.export', ['month' => 4, 'year' => 2026]))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($client)
        ->get(route('reimbursements.export', ['month' => 4, 'year' => 2026]))
        ->assertForbidden();
});

it('scopes travel rates: a member sees only their own, an admin sees all', function () {
    $memberA = User::factory()->create(['role' => 'member']);
    $memberB = User::factory()->create(['role' => 'member']);
    $admin = User::factory()->create(['role' => 'admin']);

    $rateA = TravelRate::factory()->create(['user_id' => $memberA->id]);

    expect($memberA->can('view', $rateA))->toBeTrue();
    expect($memberB->can('view', $rateA))->toBeFalse();
    expect($admin->can('view', $rateA))->toBeTrue();
    expect(User::factory()->make(['role' => 'client'])->can('viewAny', TravelRate::class))->toBeFalse();
});
