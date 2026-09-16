<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Segna quando l'utente ha fatto un accesso vero, con password e codice a due
 * fattori.
 *
 * Il rientro automatico dal cookie «Ricordami» fa scattare lo stesso evento, ma
 * non è un accesso: se aggiornasse la data, la finestra settimanale ripartirebbe
 * da sola a ogni rientro e i due fattori non verrebbero più chiesti mai.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (Auth::guard($event->guard)->viaRemember()) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
