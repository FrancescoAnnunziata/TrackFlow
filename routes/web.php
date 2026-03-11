<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'index', [
    'user' => request('user')
]);
Route::view('/hours', 'hours');
Route::view('/expenses', 'expenses');
Route::view('/profile', 'profile');

Route::post('/hours', function() {
    $date  = request('date');
    $hours = request('hours');
    $client = request('client');

    session()->put('date', $date);

    return redirect('/hours');
});
