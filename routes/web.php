<?php

use App\Support\Impersonation;
use Illuminate\Support\Facades\Route;

// Routes gestite da Filament: login, register, logout
// Le route di autenticazione sono gestite direttamente da Filament Panel
// GET /login, POST /login, GET /register, POST /register - gestite da Filament

// Termina l'impersonificazione e ripristina l'utente admin originale.
Route::get('/impersonation/leave', function () {
    Impersonation::stop();

    return redirect('/');
})->middleware('auth')->name('impersonation.leave');



