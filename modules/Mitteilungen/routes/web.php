<?php

use Illuminate\Support\Facades\Route;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

Route::prefix('mitteilungen')->name('mitteilungen.')
    ->middleware(['auth', 'active', 'module:mitteilungen'])
    ->group(function () {
        Route::get('/', fn () => view('mitteilungen::pages.index', [
            'announcements' => Announcement::current()->for(auth()->user())->with(['author', 'groups'])->orderByDesc('important')->latest()->get(),
            'dismissed' => Announcement::current()->for(auth()->user())->whereHas('dismissedBy', fn ($q) => $q->where('users.id', auth()->id()))->pluck('id')->all(),
        ]))->name('index');

        Route::get('/verwalten', fn () => view('mitteilungen::pages.manage'))->middleware('can:post-announcements')->name('manage');

        // "Ausblenden" on the start page; a plain form, so it also works from the static card
        Route::post('/{announcement}/ausblenden', function (Announcement $announcement, AnnouncementService $announcements) {
            $announcements->dismiss($announcement, auth()->user());

            return back();
        })->whereNumber('announcement')->name('dismiss');
    });
