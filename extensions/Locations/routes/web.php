<?php

use Extensions\Locations\Http\Controllers\SwitchLocationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->post('/standort/wechseln', SwitchLocationController::class)->name('locations.switch');
