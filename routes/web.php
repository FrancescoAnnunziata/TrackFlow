<?php

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Routes gestite da Filament: login, register, logout
// Le route di autenticazione sono gestite direttamente da Filament Panel
// GET /login, POST /login, GET /register, POST /register - gestite da Filament

// Termina l'impersonificazione e ripristina l'utente admin originale.
Route::get('/impersonation/leave', function () {
    Impersonation::stop();

    return redirect('/');
})->middleware('auth')->name('impersonation.leave');

// Magic link: autentica il referente del cliente (senza password) e lo porta
// sulla pagina di approvazione del preventivo. La firma copre quote + user ed
// è temporanea (vedi Quote::MAGIC_LINK_DAYS).
Route::get('/q/{quote}/access', function (Request $request, Quote $quote) {
    $user = User::find($request->integer('user'));

    abort_unless(
        $user && $user->isClient() && (int) $user->client_id === (int) $quote->client_id,
        403,
    );

    // Il magic link sostituisce la password: niente cambio password forzato.
    if ($user->must_change_password) {
        $user->forceFill(['must_change_password' => false])->save();
    }

    Auth::login($user);

    return redirect(QuoteResource::getUrl('view', ['record' => $quote]));
})->middleware('signed')->name('quote.magic');



