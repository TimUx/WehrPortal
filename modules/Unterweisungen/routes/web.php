<?php

use App\Support\FileResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Models\SessionFile;
use Modules\Unterweisungen\src\Services\InstructionService;

Route::middleware(['auth', 'active', 'module:unterweisungen'])->group(function () {
    $page = fn (string $component, string $title, array $params = []) => view('unterweisungen::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

    Route::get('/personalakte/unterweisungen', fn () => view('unterweisungen::pages.personnel-file'))->name('unterweisungen.personnel-file');

    Route::prefix('unterweisungen')->name('unterweisungen.')->group(function () use ($page) {
        Route::get('/', fn () => $page('unterweisungen.my-instructions', 'Meine Unterweisungen'))->name('index');

        // A document: for the Beauftragte and the participants
        Route::get('/dokument/{file}', function (SessionFile $file) {
            $mine = DB::table('uw_attendances')->where('session_id', $file->session_id)->where('user_id', auth()->id())->exists();
            abort_unless(InstructionService::canManage(auth()->user()) || $mine, 403);

            return FileResponse::make(base64_decode(SessionFile::whereKey($file->id)->value('data')), $file->filename);
        })->whereNumber('file')->name('file');

        Route::middleware('can:unterweisungen-manage')->group(function () use ($page) {
            Route::get('/uebersicht', fn () => $page('unterweisungen.overview', 'Unterweisungen'))->name('overview');
            Route::get('/arten', fn () => $page('unterweisungen.topics', 'Arten'))->name('topics');
            Route::get('/durchfuehren', fn () => $page('unterweisungen.session-form', 'Unterweisung durchführen'))->name('new');
            Route::get('/durchgefuehrt/{session}', fn (Session $session) => $page('unterweisungen.session-detail', $session->topic->name, ['session' => $session]))->whereNumber('session')->name('session');
            // The Nachweis with the signatures, for the file and the Unfallkasse
            Route::get('/durchgefuehrt/{session}/nachweis', fn (Session $session) => view('unterweisungen::pages.print', [
                'session' => $session->load('topic'), 'attendances' => $session->attendances()->with('user')->get()->sortBy(fn ($a) => $a->user?->name)->values(),
            ]))->whereNumber('session')->name('print');
        });
    });
});
