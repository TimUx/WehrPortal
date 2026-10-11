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
                'services' => Service::with('responsible')->whereYear('date', $year)
                    ->when($upcoming, fn ($q) => $q->whereDate('date', '>=', ServicePlan::today()))
                    ->orderBy('date')->orderBy('starts_at')->get(),
            ]);
        })->name('print');

        // Date, time and place are planned by admins only
        Route::middleware('can:plan-uebungsdienste')->group(function () {
            Route::get('/anlegen', fn () => view('uebungsdienste::pages.form', ['service' => null]))->name('create');
            Route::get('/{service}/bearbeiten', fn (Service $service) => view('uebungsdienste::pages.form', compact('service')))
                ->whereNumber('service')->name('edit');
        });
    });
