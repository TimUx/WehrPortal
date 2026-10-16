<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/sw.js', function () {
    return response(File::get(__DIR__.'/../resources/js/sw.js'), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Service-Worker-Allowed' => '/',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->name('offline.worker');

Route::get('/offline/offline.js', function () {
    abort_unless(config('offline.enabled'), 404);

    return response(File::get(__DIR__.'/../resources/js/offline.js'), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache',
    ]);
})->name('offline.script');
