<?php

use Illuminate\Support\Facades\Route;
use Modules\Einsatzbericht\src\Http\Controllers\DiveraWebhookController;

// Called by DIVERA 24/7 at every alarm; outside the web group: no session, no CSRF
Route::match(['GET', 'POST'], '/webhooks/divera/{key}', DiveraWebhookController::class)
    ->where('key', '[A-Za-z0-9]{20,100}')
    ->middleware('throttle:divera')
    ->name('einsatzbericht.divera');
