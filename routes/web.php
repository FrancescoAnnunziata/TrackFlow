<?php

use App\Models\Hour;
use Illuminate\Support\Facades\Route;

Route::view('/', 'index', [
    'user' => request('user')
]);

Route::view('/expenses', 'expenses');
Route::view('/profile', 'profile');

// Routes ORE
// Tutte le istanze registrate
Route::get('/hours', function() {
    $hours = Hour::all();

    return view('hours.index', [
        'hours' => $hours
    ]);
});

// Modifica di un'istanza
Route::get('/hours/{hour}/edit', function(Hour $hour) {
    return view('hours.edit', [
        'hour' => Hour::findOrFail($hour)
    ]);
});

Route::post('/hours', function() {
    Hour::create([
        'user_id' => 1, // TODO: sostituire con auth()->id() quando implementi l'autenticazione
        'date' => request('date'),
        'hours' => request('hours'),
        'client_id' => request('client_id'),
        'notes' => request('notes'),
        'billable' => request()->has('billable'),
    ]);

    return redirect('/hours');
});

