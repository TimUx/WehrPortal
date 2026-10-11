<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('lehrgaenge')->name('lehrgaenge.')
    ->middleware(['auth', 'active', 'module:lehrgaenge'])
    ->group(function () {
        Route::get('/', fn () => view('lehrgaenge::pages.index'))->name('index');

        Route::get('/anmeldungen', fn () => view('lehrgaenge::pages.registrations'))->middleware('can:manage-lehrgaenge')->name('registrations');
        Route::get('/lehrgangsliste', fn () => view('lehrgaenge::pages.courses'))->middleware('can:manage-lehrgaenge')->name('courses');

        // Ausbildungsleiter and Wehrführung see which Lehrgänge a Kamerad has absolviert
        Route::middleware('can:view-lehrgaenge')->group(function () {
            Route::get('/kameraden', fn () => view('lehrgaenge::pages.members'))->name('members');
            Route::get('/kameraden/{user}', fn (User $user) => view('lehrgaenge::pages.member', compact('user')))->whereNumber('user')->name('member');
        });
    });
