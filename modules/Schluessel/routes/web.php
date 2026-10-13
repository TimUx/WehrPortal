<?php

use App\Services\OrganizationSettings;
use Illuminate\Support\Facades\Route;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

Route::middleware(['auth', 'active', 'module:schluessel'])->group(function () {
    // Personalakte → Schlüssel: the own keys
    Route::get('/personalakte/schluessel', fn () => view('schluessel::pages.personnel-file'))->name('schluessel.personnel-file');

    Route::prefix('schluessel')->name('schluessel.')->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('schluessel::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        Route::get('/', fn () => $page('schluessel.my-keys', 'Meine Schlüssel'))->name('index');

        // The Schlüsselquittung: for the Schlüsselwart and the Kamerad it belongs to
        Route::get('/ausgabe/{handover}/quittung', function (Handover $handover) {
            abort_unless(KeyService::canManage(auth()->user()) || $handover->user_id === auth()->id(), 403);

            return view('schluessel::pages.receipt', ['handover' => $handover->load(['key', 'user', 'issuer', 'receiver']), 'org' => app(OrganizationSettings::class)->name()]);
        })->whereNumber('handover')->name('receipt');

        Route::middleware('can:schluessel-manage')->group(function () use ($page) {
            Route::get('/alle', fn () => $page('schluessel.key-list', 'Alle Schlüssel'))->name('list');
            Route::get('/neu', fn () => $page('schluessel.key-form', 'Neuer Schlüssel'))->name('create');
            Route::get('/{key}', fn (Key $key) => $page('schluessel.key-detail', $key->name, ['item' => $key]))->whereNumber('key')->name('show');
            Route::get('/{key}/bearbeiten', fn (Key $key) => $page('schluessel.key-form', $key->name.' bearbeiten', ['item' => $key]))->whereNumber('key')->name('edit');
        });
    });
});
