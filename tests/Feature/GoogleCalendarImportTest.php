<?php

use App\Enums\ReimbursementType;
use App\Models\GoogleCredential;
use App\Models\Reimbursement;
use App\Models\TravelRate;
use App\Models\User;
use App\Services\Google\GoogleCalendarClient;
use App\Services\Google\GoogleCalendarImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function connectedTraveller(): User
{
    $user = User::factory()->create(['role' => 'member', 'km_rate' => 0.5248]);

    GoogleCredential::create([
        'user_id' => $user->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'google_email' => 'giorgio@g8labs.it',
    ]);

    return $user;
}

function fakeWorkingLocations(array $items): void
{
    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => $items]),
    ]);
}

it('parses working locations by day, handling custom, office and home labels and multi-day spans', function () {
    $user = connectedTraveller();

    fakeWorkingLocations([
        [
            'workingLocationProperties' => ['type' => 'customLocation', 'customLocation' => ['label' => 'Fioravanti']],
            'start' => ['date' => '2026-04-03'], 'end' => ['date' => '2026-04-04'],
        ],
        [
            'workingLocationProperties' => ['type' => 'officeLocation', 'officeLocation' => ['label' => 'ALSEA']],
            'start' => ['date' => '2026-04-10'], 'end' => ['date' => '2026-04-12'], // 10 e 11
        ],
        [
            'workingLocationProperties' => ['type' => 'homeOffice'],
            'start' => ['date' => '2026-04-15'], 'end' => ['date' => '2026-04-16'],
        ],
    ]);

    $locations = GoogleCalendarClient::fromConfig()
        ->workingLocations($user, Carbon::create(2026, 4, 1), Carbon::create(2026, 4, 30));

    expect($locations)->toBe([
        '2026-04-03' => 'Fioravanti',
        '2026-04-10' => 'ALSEA',
        '2026-04-11' => 'ALSEA',
        '2026-04-15' => 'Casa',
    ]);
});

it('generates trasferte for matched labels and reports the unmatched ones', function () {
    $user = connectedTraveller();
    TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'FIORAVANTI', 'km' => 350]);
    TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'ALSEA', 'km' => 240]);

    fakeWorkingLocations([
        ['workingLocationProperties' => ['type' => 'customLocation', 'customLocation' => ['label' => 'Fioravanti']],
            'start' => ['date' => '2026-04-03'], 'end' => ['date' => '2026-04-04']],
        ['workingLocationProperties' => ['type' => 'officeLocation', 'officeLocation' => ['label' => 'ALSEA']],
            'start' => ['date' => '2026-04-10'], 'end' => ['date' => '2026-04-12']],
        ['workingLocationProperties' => ['type' => 'homeOffice'],
            'start' => ['date' => '2026-04-15'], 'end' => ['date' => '2026-04-16']],
    ]);

    $result = app(GoogleCalendarImporter::class)->importMonth($user, 2026, 4);

    expect($result['generated'])->toBe(3);
    expect($result['days'])->toBe(['2026-04-03', '2026-04-10', '2026-04-11']);
    expect($result['unmatched'])->toHaveKey('Casa');

    $travel = Reimbursement::where('user_id', $user->id)
        ->where('type', ReimbursementType::Travel)
        ->get()
        ->keyBy(fn (Reimbursement $r): string => $r->date->toDateString());
    expect($travel)->toHaveCount(3);
    expect((float) $travel['2026-04-03']->amount)->toBe(183.68); // 350 * 0.5248
    expect((float) $travel['2026-04-10']->amount)->toBe(125.95); // 240 * 0.5248
});

it('is idempotent: re-importing the same month does not duplicate trasferte', function () {
    $user = connectedTraveller();
    TravelRate::factory()->create(['user_id' => $user->id, 'tipo' => 'FIORAVANTI', 'km' => 350]);

    fakeWorkingLocations([
        ['workingLocationProperties' => ['type' => 'customLocation', 'customLocation' => ['label' => 'Fioravanti']],
            'start' => ['date' => '2026-04-03'], 'end' => ['date' => '2026-04-04']],
    ]);

    $importer = app(GoogleCalendarImporter::class);
    $importer->importMonth($user, 2026, 4);
    $importer->importMonth($user, 2026, 4);

    expect(Reimbursement::where('user_id', $user->id)->where('type', ReimbursementType::Travel)->count())->toBe(1);
});

it('refreshes an expired access token before reading the calendar', function () {
    $user = User::factory()->create(['role' => 'member', 'km_rate' => 0.5248]);
    GoogleCredential::create([
        'user_id' => $user->id,
        'access_token' => 'stale',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->subMinute(), // scaduto
    ]);

    config()->set('services.google.client_id', 'cid');
    config()->set('services.google.client_secret', 'secret');
    config()->set('services.google.redirect', 'https://trackflow.test/google/callback');

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => []]),
    ]);

    GoogleCalendarClient::fromConfig()->workingLocations($user, Carbon::create(2026, 4, 1), Carbon::create(2026, 4, 30));

    expect($user->googleCredential->fresh()->access_token)->toBe('fresh');
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
});
