<?php

use App\Support\FileResponse;
use Illuminate\Support\Facades\Route;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Services\LogbookService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

Route::prefix('fahrtenbuch')->name('fahrtenbuch.')
    ->middleware(['auth', 'active', 'module:fahrtenbuch'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('fahrtenbuch::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        // Overview of the vehicles
        Route::get('/', fn () => $page('fahrtenbuch.overview', 'Fahrtenbuch'))->name('index');
        // Start (?fahrzeug=), end (?fahrt=), enter afterwards (?nachtragen=1) or correct (?fahrt=&korrigieren=1)
        Route::get('/fahrt', fn () => $page('fahrtenbuch.trip-form', 'Fahrt'))->name('trip');
        Route::get('/tanken', fn () => $page('fahrtenbuch.fuel-form', 'Tanken'))->name('fuel');
        Route::get('/fahrzeug/{vehicle}', fn (Vehicle $vehicle) => $page('fahrtenbuch.vehicle-log', 'Fahrtenbuch '.$vehicle->name, ['vehicle' => $vehicle]))
            ->whereNumber('vehicle')->name('vehicle');

        Route::get('/beleg/{fueling}', function (Fueling $fueling) {
            abort_unless($fueling->receipt, 404);
            $vehicle = Vehicle::withoutGlobalScopes()->find($fueling->vehicle_id);
            abort_unless($vehicle && LogbookService::canAccessVehicle(auth()->user(), $vehicle), 404);

            $filename = match ($fueling->receipt_mime) {
                'application/pdf' => 'tankbeleg.pdf',
                'image/webp' => 'tankbeleg.webp',
                default => abort(404),
            };
            $bytes = base64_decode($fueling->receipt, true);
            abort_if($bytes === false, 404);

            return FileResponse::make($bytes, $filename);
        })->whereNumber('fueling')->name('receipt');

        // Fahrtenbuchwart and admins: statistics and export for Steuer and Versicherung
        Route::middleware('can:fahrtenbuch-manage')->group(function () use ($page) {
            Route::get('/auswertung', fn () => $page('fahrtenbuch.statistics', 'Auswertung Fahrtenbuch'))->name('statistics');

            Route::get('/export/{vehicle}/{year}/fahrten.csv', function (Vehicle $vehicle, int $year, LogbookService $logbook) {
                abort_unless(LogbookService::canManageVehicle(auth()->user(), $vehicle), 403);

                return response($logbook->csv($vehicle, $year), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="Fahrtenbuch-'.str($vehicle->name)->slug().'-'.$year.'.csv"',
                ]);
            })->whereNumber(['vehicle', 'year'])->name('export.trips');

            Route::get('/export/{vehicle}/{year}/tanken.csv', function (Vehicle $vehicle, int $year, LogbookService $logbook) {
                abort_unless(LogbookService::canManageVehicle(auth()->user(), $vehicle), 403);

                return response($logbook->fuelCsv($vehicle, $year), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="Tankbuch-'.str($vehicle->name)->slug().'-'.$year.'.csv"',
                ]);
            })->whereNumber(['vehicle', 'year'])->name('export.fuel');

            Route::get('/export/{vehicle}/{year}/druck', function (Vehicle $vehicle, int $year, LogbookService $logbook) {
                abort_unless(LogbookService::canManageVehicle(auth()->user(), $vehicle), 403);

                return view('fahrtenbuch::pages.print', [
                    'vehicle' => $vehicle, 'year' => $year, 'trips' => $logbook->yearTrips($vehicle, $year, withVoided: true),
                    'consumption' => $logbook->consumption($vehicle, $year),
                ]);
            })->whereNumber(['vehicle', 'year'])->name('export.print');
        });
    });
