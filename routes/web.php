<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Login route (for error handling)
Route::get('/login', function () {
    return response()->json([
        'status' => 'error',
        'message' => 'Unauthenticated'
    ], 401);
})->name('login');
