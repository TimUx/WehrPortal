<?php

use Illuminate\Support\Facades\Route;
use Modules\Ausruestung\src\Models\Equipment;

Route::prefix('ausruestung')->name('ausruestung.')
    ->middleware(['auth', 'active', 'module:ausruestung'])
    ->group(function () {
        Route::get('/', fn () => view('ausruestung::pages.index'))->name('index');
        Route::get('/geraete', fn () => view('ausruestung::pages.equipment'))->name('equipment');
        Route::get('/geraete/{equipment}', fn (Equipment $equipment) => view('ausruestung::pages.show', compact('equipment')))
            ->whereNumber('equipment')
            ->name('show');
    });
