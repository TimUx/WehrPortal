<?php

use Extensions\Vereinsverwaltung\Http\Controllers\MembershipApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/vereinsbeitritt', [MembershipApplicationController::class, 'create'])->name('verein.application');
Route::post('/vereinsbeitritt', [MembershipApplicationController::class, 'store'])
    ->middleware('throttle:vereinsverwaltung-application')
    ->name('verein.application.store');

Route::prefix('verein')->name('verein.')->middleware(['auth', 'active', 'module:verein', 'can:manage-verein'])->group(function () {
    Route::get('/', fn () => view('vereinsverwaltung::pages.board', ['livewireComponent' => 'vereinsverwaltung.board']))
        ->name('index');
});
