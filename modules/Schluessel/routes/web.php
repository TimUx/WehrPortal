<?php

use App\Services\OrganizationSettings;
use Illuminate\Support\Facades\Route;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

Route::middleware(['auth', 'active', 'module:schluessel'])->group(function () {
    // The own keys are on the Profil now; the old address leads there
    Route::get('/personalakte/schluessel', fn () => redirect(route('profile').'#schluessel'))->name('schluessel.personnel-file');

    Route::prefix('schluessel')->name('schluessel.')->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('schluessel::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        // All keys for the Schlüsselwart; everyone else finds their own on the Profil
        Route::get('/', fn () => KeyService::canManage(auth()->user()) ? $page('schluessel.key-list', 'Schlüssel') : redirect(route('profile').'#schluessel'))->name('index');

        // The Schlüsselquittung: for the Schlüsselwart and the Kamerad it belongs to
        Route::get('/ausgabe/{handover}/quittung', function (Handover $handover) {
            abort_unless(KeyService::canManage(auth()->user()) || $handover->user_id === auth()->id(), 403);

            return view('schluessel::pages.receipt', ['handover' => $handover->load(['key', 'user', 'issuer', 'receiver']), 'org' => app(OrganizationSettings::class)->name()]);
        })->whereNumber('handover')->name('receipt');

        Route::middleware('can:schluessel-manage')->group(function () use ($page) {
            Route::get('/alle', fn () => redirect()->route('schluessel.index'))->name('list');
            Route::get('/neu', fn () => $page('schluessel.key-form', 'Neuer Schlüssel'))->name('create');
            Route::get('/{key}', fn (Key $key) => $page('schluessel.key-detail', $key->name, ['item' => $key]))->whereNumber('key')->name('show');
            Route::get('/{key}/bearbeiten', fn (Key $key) => $page('schluessel.key-form', $key->name.' bearbeiten', ['item' => $key]))->whereNumber('key')->name('edit');
        });
    });
});
