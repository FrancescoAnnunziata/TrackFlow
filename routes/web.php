<?php

use App\Filament\Pages\FattureInCloud;
use App\Http\Controllers\AssetLabelController;
use App\Http\Controllers\AssetLookupController;
use App\Http\Controllers\DeviceExportController;
use App\Http\Controllers\QuoteDocumentController;
use App\Http\Middleware\QuoteMagicAccess;
use App\Models\Quote;
use App\Models\User;
use App\Services\Fic\FicClient;
use App\Services\Fic\FicException;
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

// Il preventivo come documento: lettura integrale, firma grafica e PDF. Fuori
// dal pannello perché ci arriva anche il referente del cliente, che non ha
// password: per lui vale la firma del link ricevuto via email (vedi
// QuoteMagicAccess e Quote::MAGIC_LINK_DAYS).
Route::middleware(QuoteMagicAccess::class)->group(function () {
    Route::get('/q/{quote}/documento', [QuoteDocumentController::class, 'show'])->name('quote.document');
    Route::post('/q/{quote}/firma', [QuoteDocumentController::class, 'sign'])->name('quote.sign');
    Route::post('/q/{quote}/rifiuto', [QuoteDocumentController::class, 'reject'])->name('quote.reject');
    Route::get('/q/{quote}/pdf', [QuoteDocumentController::class, 'pdf'])->name('quote.pdf');
});

// Vecchio magic link, quello delle email già spedite: autenticava e rimbalzava
// sul documento. Resta valido finché non scade; i link nuovi puntano dritti al
// documento.
Route::get('/q/{quote}/access', function (Request $request, Quote $quote) {
    $user = User::find($request->integer('user'));

    abort_unless(
        $user && $user->isClient() && $user->belongsToClientId($quote->client_id),
        403,
    );

    Auth::login($user);
    session()->put('quote_magic_login', true);

    return redirect()->route('quote.document', $quote);
})->middleware('signed')->name('quote.magic');

// Asset Management: lookup pubblico via QR (qr_token). Mostra la scheda
// completa se l'utente è autenticato e autorizzato, altrimenti una pagina
// minimale senza dati sensibili.
Route::get('/assets/lookup/{qrToken}', AssetLookupController::class)->name('assets.lookup');

// Etichette stampabili (tipo Dymo). Richiedono autenticazione.
Route::middleware('auth')->group(function () {
    Route::get('/assets/export', [DeviceExportController::class, 'export'])->name('assets.export');
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
