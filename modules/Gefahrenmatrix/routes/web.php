<?php

use Illuminate\Support\Facades\Route;

Route::get('/gefahrenmatrix', fn () => view('gefahrenmatrix::pages.index'))
    ->middleware(['auth', 'active', 'module:gefahrenmatrix'])
    ->name('gefahrenmatrix.index');
