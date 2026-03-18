<?php

use App\Http\Controllers\HourController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;

Route::view('/', 'index', [
    'user' => request('user')
]);

Route::view('/expenses', 'expenses');
Route::view('/profile', 'profile');

// Routes ORE
Route::get('/hours', [HourController::class, 'index']);
Route::get('/hours/create', [HourController::class, 'create']);
Route::post('/hours', [HourController::class, 'store']);
Route::get('/hours/{hour}', [HourController::class, 'show']);
Route::get('/hours/{hour}/edit', [HourController::class, 'edit']);
Route::patch('/hours/{hour}', [HourController::class, 'update']);
Route::delete('/hours/{hour}', [HourController::class, 'destroy']);

Route::get('/register', [RegisteredUserController::class, 'create']);
Route::post('/register', [RegisteredUserController::class, 'store']);

Route::get('/login', [SessionController::class, 'create']);
Route::post('/login', [SessionController::class, 'store']);
Route::delete('/logout', [SessionController::class, 'destroy']);
