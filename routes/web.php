<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/play');

Route::redirect('dashboard', '/play')->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
