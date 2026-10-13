<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Lehrgaenge\src\Support\CoursePlan;

// Personalakte → Lehrgänge: the own absolvierte and coming Lehrgänge
Route::get('/personalakte/lehrgaenge', fn () => view('lehrgaenge::pages.personnel-file'))
    ->middleware(['auth', 'active', 'module:lehrgaenge'])->name('lehrgaenge.personnel-file');

Route::prefix('lehrgaenge')->name('lehrgaenge.')
    ->middleware(['auth', 'active', 'module:lehrgaenge'])
    ->group(function () {
        Route::get('/', fn () => view('lehrgaenge::pages.index'))->name('index');

        Route::get('/anmeldungen', fn () => view('lehrgaenge::pages.registrations'))->middleware('can:manage-lehrgaenge')->name('registrations');
        Route::get('/lehrgangsliste', fn () => view('lehrgaenge::pages.courses'))->middleware('can:manage-lehrgaenge')->name('courses');

        // Ausbildungsleiter and Wehrführung see which Lehrgänge a Kamerad has absolviert
        Route::middleware('can:view-lehrgaenge')->group(function () {
            Route::get('/plan', function () {
                $year = request()->filled('jahr') ? max(2000, min(2100, request()->integer('jahr'))) : null;

                return view('lehrgaenge::pages.plan', ['year' => $year, 'months' => CoursePlan::rows($year)]);
            })->name('plan');
            Route::get('/kameraden', fn () => view('lehrgaenge::pages.members'))->name('members');
            Route::get('/kameraden/{user}', fn (User $user) => view('lehrgaenge::pages.member', compact('user')))->whereNumber('user')->name('member');
        });
    });
