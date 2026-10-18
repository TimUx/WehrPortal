<?php

use Extensions\Dateien\Http\Controllers\DownloadController;
use Extensions\Dateien\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('dateien')->name('dateien.')
    ->middleware(['auth', 'active', 'module:dateien'])
    ->group(function () {
        Route::get('/', fn () => view('dateien::livewire.page'))->name('index');
        Route::post('/hochladen', UploadController::class)->middleware('throttle:dateien-upload')->name('upload');
        Route::get('/dateien/{node}/versionen/{version}', DownloadController::class)
            ->whereNumber(['node', 'version'])->middleware('throttle:dateien-download')->name('download');
    });
