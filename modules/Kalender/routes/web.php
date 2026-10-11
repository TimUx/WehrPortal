<?php

use Illuminate\Support\Facades\Route;
use Modules\Kalender\src\Http\Controllers\FeedController;
use Modules\Kalender\src\Http\Controllers\PublicCalendarController;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;

// Subscription feed for phone calendars; the token in the URL replaces the login
Route::get('/kalender/abo/{token}.ics', FeedController::class)
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('kalender.feed');

// Public events for the Wehr's website (iframe and iCal), no login
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/kalender/oeffentlich', [PublicCalendarController::class, 'page'])->name('kalender.public');
    Route::get('/kalender/oeffentlich.ics', [PublicCalendarController::class, 'ics'])->name('kalender.public.ics');
});

Route::prefix('kalender')->name('kalender.')
    ->middleware(['auth', 'active', 'module:kalender'])
    ->group(function () {
        Route::get('/', fn () => view('kalender::pages.index'))->name('index');

        Route::get('/termine/neu', fn () => view('kalender::pages.form', ['event' => null]))
            ->middleware('can:manage,'.Event::class)->name('create');

        Route::get('/termine/{event}', fn (Event $event) => view('kalender::pages.show', compact('event')))
            ->whereNumber('event')->middleware('can:view,event')->name('show');

        Route::get('/termine/{event}/bearbeiten', fn (Event $event) => view('kalender::pages.form', compact('event')))
            ->whereNumber('event')->middleware('can:manage,event')->name('edit');

        Route::get('/termine/{event}/teilnehmer.csv', fn (Event $event, EventService $events) => response($events->participantsCsv($event), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="teilnehmer-'.$event->starts_at->copy()->setTimezone('Europe/Berlin')->format('Y-m-d').'.csv"',
        ]))->whereNumber('event')->middleware('can:manage,event')->name('participants');

        Route::get('/externe-kalender', fn () => view('kalender::pages.sources'))
            ->middleware('can:manage,'.Event::class)->name('sources');
    });
