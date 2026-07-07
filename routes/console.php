<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Solleciti preventivi inviati e non ancora accettati/rifiutati (a 5 e 10 giorni).
Schedule::command('quotes:send-reminders')->dailyAt('09:00')->timezone('Europe/Rome');

// Promemoria giornaliero "segna le ore" agli admin/member che lo hanno attivato.
Schedule::command('hours:send-reminders')->weekdays()->at('18:00')->timezone('Europe/Rome');
