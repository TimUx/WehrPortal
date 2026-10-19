<?php

use Illuminate\Support\Facades\Route;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

Route::prefix('uebungsdienste')->name('uebungsdienste.')
    ->middleware(['auth', 'active', 'module:uebungsdienste'])
    ->group(function () {
        Route::get('/', fn () => view('uebungsdienste::pages.index'))->name('index');

        // Printable plan of a year; "Als PDF speichern" in the browser's print dialog
        Route::get('/druck', function () {
            $year = request()->integer('jahr') ?: ServicePlan::today()->year;
            $upcoming = request()->boolean('kommende');

            return view('uebungsdienste::pages.print', [
                'year' => $year,
                'upcoming' => $upcoming,
                'services' => app(ServicePlan::class)->forPlanner(auth()->user(), $year)
                    ->when($upcoming, fn ($services) => $services->filter(fn (Service $service) => $service->date->gte(ServicePlan::today())))
                    ->values(),
            ]);
        })->name('print');

        // The Wehrführung and admins plan date, time and place
        Route::middleware('can:plan-uebungsdienste')->group(function () {
            Route::get('/anlegen', fn () => view('uebungsdienste::pages.form', ['service' => null]))->name('create');
            // Orte & Kleidung: the same lists as in the Kalender and every other module
            Route::get('/orte-kleidung', fn () => view('uebungsdienste::pages.places'))->name('places');
            Route::get('/{service}/bearbeiten', fn (Service $service) => view('uebungsdienste::pages.form', compact('service')))
                ->whereNumber('service')->name('edit');
        });
    });
