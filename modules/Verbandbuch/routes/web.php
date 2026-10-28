<?php

use Illuminate\Support\Facades\Route;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

Route::middleware(['auth', 'active', 'module:verbandbuch'])->group(function () {
    // Personalakte → Verbandbuch: the entries where the Kamerad was hurt
    Route::get('/personalakte/verbandbuch', fn () => view('verbandbuch::pages.personnel-file'))->name('verbandbuch.personnel-file');

    Route::prefix('verbandbuch')->name('verbandbuch.')->group(function () {
        Route::get('/', fn () => view('verbandbuch::pages.index'))->name('index');
        Route::get('/neu', fn () => view('verbandbuch::pages.form'))->name('create');

        Route::get('/{entry}', function (Entry $entry) {
            abort_unless(VerbandbuchService::canView(auth()->user(), $entry), 403);

            return view('verbandbuch::pages.show', compact('entry'));
        })->whereNumber('entry')->name('show');

        Route::get('/{entry}/druck', function (Entry $entry, VerbandbuchService $service) {
            abort_unless(VerbandbuchService::canView(auth()->user(), $entry), 403);
            $service->log($entry, auth()->user(), 'printed');

            return response()->view('verbandbuch::pages.print', ['entries' => collect([$entry]), 'title' => 'Verbandbuch '.$entry->code(), 'service' => $service])
                ->header('Cache-Control', 'no-store');
        })->whereNumber('entry')->name('print');

        // The caller's Standort book for local Wehrführung; global book for GBI/SBI and admins.
        Route::get('/jahr/{year}/druck', function (int $year, VerbandbuchService $service) {
            $entries = VerbandbuchService::managedEntries(auth()->user())->where('year', $year)->orderBy('number')->get();
            $entries->each(fn (Entry $entry) => $service->log($entry, auth()->user(), 'printed'));

            return response()->view('verbandbuch::pages.print', ['entries' => $entries, 'title' => 'Verbandbuch '.$year, 'year' => $year, 'service' => $service])
                ->header('Cache-Control', 'no-store');
        })->whereNumber('year')->middleware('can:verbandbuch-manage')->name('print-year');
    });
});
