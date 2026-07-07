<?php

use App\Filament\Pages\Auth\NotificationPreferences;
use App\Models\Hour;
use App\Models\User;
use App\Notifications\LogHoursReminderNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('reminds opted-in admin/member who have not logged hours today', function () {
    Notification::fake();

    $member = User::factory()->create(['role' => 'member', 'hours_reminder_opt_in' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'hours_reminder_opt_in' => true]);

    $this->artisan('hours:send-reminders')->assertSuccessful();

    Notification::assertSentTo($member, LogHoursReminderNotification::class);
    Notification::assertSentTo($admin, LogHoursReminderNotification::class);
});

it('does not remind users who opted out, clients, or those who already logged hours today', function () {
    Notification::fake();

    $optedOut = User::factory()->create(['role' => 'member', 'hours_reminder_opt_in' => false]);
    $client = User::factory()->create(['role' => 'client', 'hours_reminder_opt_in' => true]);
    $alreadyLogged = User::factory()->create(['role' => 'member', 'hours_reminder_opt_in' => true]);
    Hour::create(['user_id' => $alreadyLogged->id, 'date' => today(), 'hours' => 8]);

    $this->artisan('hours:send-reminders')->assertSuccessful();

    Notification::assertNotSentTo($optedOut, LogHoursReminderNotification::class);
    Notification::assertNotSentTo($client, LogHoursReminderNotification::class);
    Notification::assertNotSentTo($alreadyLogged, LogHoursReminderNotification::class);
});

it('lets a user toggle the hours reminder preference', function () {
    $user = User::factory()->create(['role' => 'member', 'hours_reminder_opt_in' => false]);

    Livewire::actingAs($user)
        ->test(NotificationPreferences::class)
        ->fillForm(['hours_reminder_opt_in' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->hours_reminder_opt_in)->toBeTrue();
});
