<?php

use Extensions\Dateien\Http\Controllers\DownloadController;
use Extensions\Dateien\Http\Controllers\OfficeEditorController;
use Extensions\Dateien\Http\Controllers\OnlyOfficeController;
use Extensions\Dateien\Http\Controllers\UploadController;
use Extensions\Dateien\Http\Controllers\WopiController;
use Illuminate\Support\Facades\Route;

Route::prefix('dateien')->name('dateien.')
    ->middleware(['web', 'auth', 'active', 'module:dateien'])
    ->group(function () {
        Route::get('/', fn () => view('dateien::livewire.page'))->name('index');
        Route::post('/hochladen', UploadController::class)->middleware('throttle:dateien-upload')->name('upload');
        Route::get('/dateien/{node}/versionen/{version}', DownloadController::class)
            ->whereNumber(['node', 'version'])->middleware('throttle:dateien-download')->name('download');
        Route::get('/dateien/{node}/office', OfficeEditorController::class)
            ->whereNumber('node')->middleware('throttle:dateien-download')->name('office');
    });

// Called by the office server, not by browsers: no session, no cookies, no CSRF. Only the session
// token (and the JWT or WOPI proof of the configured server) authorizes; rights are checked again.
Route::prefix('dateien/office')->name('dateien.office.')
    ->middleware('throttle:dateien-office')
    ->group(function () {
        Route::get('/onlyoffice/{token}/datei', [OnlyOfficeController::class, 'file'])
            ->where('token', '[A-Za-z0-9]{64}')->name('onlyoffice.file');
        Route::post('/onlyoffice/{token}/rueckruf', [OnlyOfficeController::class, 'callback'])
            ->where('token', '[A-Za-z0-9]{64}')->name('onlyoffice.callback');
        Route::get('/wopi/files/{node}', [WopiController::class, 'checkFileInfo'])->whereNumber('node')->name('wopi.file');
        Route::post('/wopi/files/{node}', [WopiController::class, 'fileOperation'])->whereNumber('node');
        Route::get('/wopi/files/{node}/contents', [WopiController::class, 'getFile'])->whereNumber('node')->name('wopi.contents');
        Route::post('/wopi/files/{node}/contents', [WopiController::class, 'putFile'])->whereNumber('node');
    });
