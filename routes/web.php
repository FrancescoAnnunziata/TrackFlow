<?php

use App\Filament\Pages\Auth\TravelSettings;
use App\Filament\Pages\FattureInCloud;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Http\Controllers\AssetLabelController;
use App\Http\Controllers\AssetLookupController;
use App\Http\Controllers\DeviceExportController;
use App\Http\Controllers\ReimbursementExportController;
use App\Models\Quote;
use App\Models\User;
use App\Services\Fic\FicClient;
use App\Services\Fic\FicException;
use App\Services\Google\GoogleCalendarClient;
use App\Services\Google\GoogleException;
use App\Support\Impersonation;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
        $user && $user->isClient() && $user->belongsToClientId($quote->client_id),
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
    Route::get('/assets/export', [DeviceExportController::class, 'export'])->name('assets.export');
    Route::get('/rimborsi/export', [ReimbursementExportController::class, 'export'])->name('reimbursements.export');
    Route::get('/assets/labels', [AssetLabelController::class, 'bulk'])->name('assets.labels');
    Route::get('/assets/{device}/label', [AssetLabelController::class, 'show'])->name('assets.label');
});

// Fatture in Cloud — OAuth2 Authorization Code flow.
// /fic/connect: avvia il consenso; /fic/callback: scambia il code coi token.
// Solo admin: rispecchia il gate isAdmin() usato dal pannello.
Route::middleware('auth')->group(function () {
    Route::get('/fic/connect', function (Request $request) {
        abort_unless($request->user()->isAdmin(), 403);

        $state = Str::random(40);
        session()->put('fic_oauth_state', $state);

        return redirect()->away(FicClient::fromConfig()->authorizeUrl($state));
    })->name('fic.connect');

    Route::get('/fic/callback', function (Request $request) {
        abort_unless($request->user()->isAdmin(), 403);

        $expectedState = session()->pull('fic_oauth_state');

        $fail = function (string $message) {
            FilamentNotification::make()->danger()->title('Connessione fallita')->body($message)->send();

            return redirect(FattureInCloud::getUrl());
        };

        if ($request->filled('error')) {
            return $fail('Autorizzazione negata su Fatture in Cloud.');
        }

        if (! $request->filled('code') || ! $request->filled('state') || $request->query('state') !== $expectedState) {
            return $fail('Richiesta OAuth non valida o scaduta. Riprova la connessione.');
        }

        try {
            $credential = FicClient::fromConfig()->exchangeCode($request->query('code'));
        } catch (FicException $e) {
            return $fail($e->getMessage());
        }

        FilamentNotification::make()
            ->success()
            ->title('Fatture in Cloud collegato')
            ->body($credential->company_name
                ? 'Azienda collegata: '.$credential->company_name.'.'
                : 'Connessione completata.')
            ->send();

        return redirect(FattureInCloud::getUrl());
    })->name('fic.callback');
});

// Google Calendar — OAuth2 Authorization Code flow, PER-UTENTE.
// /google/connect: avvia il consenso; /google/callback: scambia il code.
// Ogni utente interno (non cliente) collega il proprio account.
Route::middleware('auth')->group(function () {
    Route::get('/google/connect', function (Request $request) {
        abort_if($request->user()->isClient(), 403);

        $state = Str::random(40);
        session()->put('google_oauth_state', $state);

        return redirect()->away(GoogleCalendarClient::fromConfig()->authorizeUrl($state));
    })->name('google.connect');

    Route::get('/google/callback', function (Request $request) {
        abort_if($request->user()->isClient(), 403);

        $expectedState = session()->pull('google_oauth_state');

        $fail = function (string $message) {
            FilamentNotification::make()->danger()->title('Connessione fallita')->body($message)->send();

            return redirect(TravelSettings::getUrl());
        };

        if ($request->filled('error')) {
            return $fail('Autorizzazione negata su Google.');
        }

        if (! $request->filled('code') || ! $request->filled('state') || $request->query('state') !== $expectedState) {
            return $fail('Richiesta OAuth non valida o scaduta. Riprova la connessione.');
        }

        try {
            $credential = GoogleCalendarClient::fromConfig()->exchangeCode($request->query('code'), $request->user());
        } catch (GoogleException $e) {
            return $fail($e->getMessage());
        }

        FilamentNotification::make()
            ->success()
            ->title('Google Calendar collegato')
            ->body($credential->google_email
                ? 'Account collegato: '.$credential->google_email.'.'
                : 'Connessione completata.')
            ->send();

        return redirect(TravelSettings::getUrl());
    })->name('google.callback');
});
