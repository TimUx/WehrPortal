<?php

use App\Models\User;
use App\Support\FileResponse;
use Extensions\Locations\Http\Controllers\SwitchLocationController;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->post('/standort/wechseln', SwitchLocationController::class)->name('locations.switch');

Route::middleware(['auth', 'active'])->get('/standorte/{location}/wappen', function (Location $location) {
    $actor = User::withoutGlobalScopes()->findOrFail(auth()->id());
    $access = app(LocationAccess::class);
    $access->forget($actor);
    abort_unless($actor->is_active && $access->canSee($actor, (int) $location->getKey()), 403);
    abort_unless(filled($location->crest_data), 404);

    // Revalidate access on every request, including after a membership has been removed.
    return FileResponse::make(base64_decode($location->crest_data), 'Standortwappen.webp', 'private, no-store');
})->whereNumber('location')->name('locations.crest');
