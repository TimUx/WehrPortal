<?php

use Illuminate\Support\Facades\Route;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

Route::prefix('fahrtenbuch')->name('fahrtenbuch.')
    ->middleware(['auth', 'active', 'module:fahrtenbuch'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('fahrtenbuch::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        // Overview; ?kiosk=1 is the big view for the tablet in the Gerätehaus
        Route::get('/', fn () => $page('fahrtenbuch.overview', 'Fahrtenbuch'))->name('index');
        // Start (?fahrzeug=), end (?fahrt=), enter afterwards (?nachtragen=1) or correct (?fahrt=&korrigieren=1)
        Route::get('/fahrt', fn () => $page('fahrtenbuch.trip-form', 'Fahrt'))->name('trip');
        Route::get('/tanken', fn () => $page('fahrtenbuch.fuel-form', 'Tanken'))->name('fuel');
        Route::get('/fahrzeug/{vehicle}', fn (Vehicle $vehicle) => $page('fahrtenbuch.vehicle-log', 'Fahrtenbuch '.$vehicle->name, ['vehicle' => $vehicle]))
            ->whereNumber('vehicle')->name('vehicle');

        Route::get('/beleg/{fueling}', function (Fueling $fueling) {
            abort_unless($fueling->receipt, 404);

            return response(base64_decode($fueling->receipt), 200, ['Content-Type' => $fueling->receipt_mime, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
        })->whereNumber('fueling')->name('receipt');

        // Fahrtenbuchwart and admins: statistics and export for Steuer and Versicherung
        Route::middleware('can:fahrtenbuch-manage')->group(function () use ($page) {
            Route::get('/auswertung', fn () => $page('fahrtenbuch.statistics', 'Auswertung Fahrtenbuch'))->name('statistics');

            Route::get('/export/{vehicle}/{year}/fahrten.csv', fn (Vehicle $vehicle, int $year, LogbookService $logbook) => response($logbook->csv($vehicle, $year), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Fahrtenbuch-'.str($vehicle->name)->slug().'-'.$year.'.csv"',
            ]))->whereNumber(['vehicle', 'year'])->name('export.trips');

            Route::get('/export/{vehicle}/{year}/tanken.csv', fn (Vehicle $vehicle, int $year, LogbookService $logbook) => response($logbook->fuelCsv($vehicle, $year), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Tankbuch-'.str($vehicle->name)->slug().'-'.$year.'.csv"',
            ]))->whereNumber(['vehicle', 'year'])->name('export.fuel');

            Route::get('/export/{vehicle}/{year}/druck', fn (Vehicle $vehicle, int $year, LogbookService $logbook) => view('fahrtenbuch::pages.print', [
                'vehicle' => $vehicle, 'year' => $year, 'trips' => $logbook->yearTrips($vehicle, $year, withVoided: true),
                'consumption' => $logbook->consumption($vehicle, $year),
            ]))->whereNumber(['vehicle', 'year'])->name('export.print');
        });
    });
