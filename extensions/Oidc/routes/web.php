<?php

use Extensions\Oidc\Http\Controllers\OidcController;
use Extensions\Oidc\Http\Middleware\EnsureOidcActive;
use Illuminate\Support\Facades\Route;

// Local system admins log in here with the magic link, also when the login page only offers the IdP
Route::get('/anmelden/lokal', fn () => view('auth.login', ['localLogin' => true]))->name('oidc.local');

Route::middleware([EnsureOidcActive::class, 'throttle:20,1'])->group(function () {
    Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});
