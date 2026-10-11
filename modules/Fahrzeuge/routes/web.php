<?php

use Illuminate\Support\Facades\Route;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Attachment;
use Modules\Fahrzeuge\src\Models\Profile;

Route::prefix('fahrzeuge')->name('fahrzeuge.')
    ->middleware(['auth', 'active', 'module:fahrzeuge'])
    ->group(function () {
        Route::get('/', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.fleet', 'title' => 'Fahrzeuge']))->name('index');
        Route::get('/neu', fn () => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-form', 'title' => 'Neues Fahrzeug']))
            ->middleware('can:fahrzeuge-manage')->name('create');
        Route::get('/{vehicle}', fn (Vehicle $vehicle) => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-detail', 'title' => $vehicle->name, 'params' => ['vehicle' => $vehicle]]))
            ->whereNumber('vehicle')->name('show');
        Route::get('/{vehicle}/bearbeiten', fn (Vehicle $vehicle) => view('fahrzeuge::pages.page', ['livewireComponent' => 'fahrzeuge.vehicle-form', 'title' => $vehicle->name.' bearbeiten', 'params' => ['vehicle' => $vehicle]]))
            ->whereNumber('vehicle')->middleware('can:fahrzeuge-manage')->name('edit');

        // Photo, versioned by ?v= so it can be cached for long
        Route::get('/{vehicle}/foto', function (Vehicle $vehicle) {
            $photo = Profile::where('vehicle_id', $vehicle->id)->value('photo');
            abort_unless($photo, 404);

            return response(base64_decode($photo), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=31536000, immutable']);
        })->whereNumber('vehicle')->name('photo');

        Route::get('/anhaenge/{attachment}', fn (Attachment $attachment) => response(base64_decode($attachment->data), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]))->whereNumber('attachment')->name('attachment');
    });
