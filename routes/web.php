<?php

use App\Filament\Resources\Quotes\QuoteResource;
use App\Http\Controllers\AssetLabelController;
use App\Http\Controllers\AssetLookupController;
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

    Auth::login($user);

    // Il magic link sostituisce la password: salta il gate "cambio password
    // obbligatorio" solo per QUESTA sessione, senza disattivarlo in modo
    // permanente sull'account (vedi MustChangePassword middleware).
    session()->put('quote_magic_login', true);

    return redirect(QuoteResource::getUrl('view', ['record' => $quote]));
})->middleware('signed')->name('quote.magic');

// Asset Management: lookup pubblico via QR (qr_token). Mostra la scheda
// completa se l'utente è autenticato e autorizzato, altrimenti una pagina
// minimale senza dati sensibili.
Route::get('/assets/lookup/{qrToken}', AssetLookupController::class)->name('assets.lookup');

// Etichette stampabili (tipo Dymo). Richiedono autenticazione.
Route::middleware('auth')->group(function () {
    Route::get('/assets/labels', [AssetLabelController::class, 'bulk'])->name('assets.labels');
    Route::get('/assets/{device}/label', [AssetLabelController::class, 'show'])->name('assets.label');
});



