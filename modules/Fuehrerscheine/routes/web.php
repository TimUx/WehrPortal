<?php

use Illuminate\Support\Facades\Route;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Models\Photo;
use Modules\Fuehrerscheine\src\Services\LicenseService;

Route::middleware(['auth', 'active', 'module:fuehrerscheine'])->group(function () {
    $page = fn (string $component, string $title) => view('fuehrerscheine::pages.page', ['livewireComponent' => $component, 'title' => $title]);

    Route::get('/personalakte/fuehrerschein', fn () => view('fuehrerscheine::pages.personnel-file'))->name('fuehrerscheine.personnel-file');

    Route::prefix('fuehrerscheine')->name('fuehrerscheine.')->group(function () use ($page) {
        Route::get('/', fn () => $page('fuehrerscheine.my-license', 'Mein Führerschein'))->name('index');
        Route::get('/pruefen', fn () => $page('fuehrerscheine.review', 'Führerscheine prüfen'))->middleware('can:fuehrerscheine-manage')->name('review');

        // A photo until the check: for the Prüfer and the Kamerad it belongs to; never cached
        Route::get('/foto/{photo}', function (Photo $photo) {
            $owner = License::whereKey($photo->license_id)->value('user_id');
            abort_unless(LicenseService::canManage(auth()->user()) || $owner === auth()->id(), 403);

            return response(base64_decode(Photo::whereKey($photo->id)->value('data')), 200, ['Content-Type' => $photo->mime, 'Cache-Control' => 'no-store']);
        })->whereNumber('photo')->name('photo');
    });
});
