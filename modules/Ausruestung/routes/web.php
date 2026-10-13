<?php

use App\Services\OrganizationSettings;
use Illuminate\Support\Facades\Route;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Support\PublicScan;

// Geräteinfo without login, when a Gerätewart switched it on: what matters at the device, nothing more
Route::get('/geraeteinfo/{code}', function (string $code) {
    $equipment = Equipment::with(['category', 'compartment.location', 'location'])->where('code', $code)->first()
        ?? Equipment::with(['category', 'compartment.location', 'location'])->where('barcode', $code)->firstOrFail();
    if (auth()->check() || ! PublicScan::enabled()) {
        return redirect()->route('ausruestung.show', $equipment);
    }

    return response()->view('ausruestung::pages.public', ['equipment' => $equipment, 'org' => app(OrganizationSettings::class)->name()])
        ->header('Referrer-Policy', 'no-referrer');
})->where('code', '[A-Za-z0-9._-]{1,100}')->middleware('throttle:60,1')->name('ausruestung.public');

Route::prefix('ausruestung')->name('ausruestung.')
    ->middleware(['auth', 'active', 'module:ausruestung'])
    ->group(function () {
        Route::get('/', fn () => view('ausruestung::pages.index'))->name('index');
        Route::get('/geraete', fn () => view('ausruestung::pages.equipment'))->name('equipment');
        Route::get('/geraete/{equipment}', fn (Equipment $equipment) => view('ausruestung::pages.show', compact('equipment')))
            ->whereNumber('equipment')
            ->name('show');
    });
