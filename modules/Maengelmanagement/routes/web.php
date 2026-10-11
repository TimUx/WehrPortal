<?php

use Illuminate\Support\Facades\Route;
use Modules\Maengelmanagement\src\Http\Controllers\DefectPhotoController;
use Modules\Maengelmanagement\src\Models\Defect;

Route::prefix('maengelmanagement')->name('maengelmanagement.')
    ->middleware(['auth', 'active', 'module:maengelmanagement'])
    ->group(function () {
        Route::get('/', fn () => view('maengelmanagement::pages.index'))->name('index');
        Route::get('/melden', fn () => view('maengelmanagement::pages.report'))->name('report');
        Route::get('/fotos/{photo}', DefectPhotoController::class)->name('photo');
        Route::get('/{defect}', fn (Defect $defect) => view('maengelmanagement::pages.show', compact('defect')))
            ->whereNumber('defect')
            ->name('show');
    });
