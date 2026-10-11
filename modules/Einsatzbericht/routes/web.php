<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationPhoto;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Services\SituationService;

// QR access for external Wehren at large Einsätze: no login, the key in the address decides
Route::get('/extern/{key}', fn (string $key) => response()->view('einsatzbericht::pages.external', compact('key'))
    ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer'))
    ->where('key', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('einsatzbericht.external');

Route::middleware(['auth', 'active', 'module:einsatzbericht'])->group(function () {
    // Personalakte → Einsätze: every Kamerad sees the own Einsätze
    Route::get('/personalakte/einsaetze', fn () => view('einsatzbericht::pages.personnel-file'))->name('einsatzbericht.personnel-file');

    Route::prefix('einsatzbericht')->name('einsatzbericht.')->group(function () {
        // Kameraden without a role see the running Einsätze (for Lagemeldungen) and their own
        Route::get('/', fn () => ReportService::canLead(auth()->user())
            ? view('einsatzbericht::pages.index')
            : view('einsatzbericht::pages.running', [
                'running' => Report::where('status', 'active')->whereNull('ended_at')->orderByDesc('alarmed_at')->get(),
            ]))->name('index');

        // Lagemeldungen: everyone with the module while the Einsatz runs, Einsatzleiter and Wehrführung always
        Route::get('/{report}/lage', function (Report $report) {
            abort_unless(SituationService::canSee(auth()->user(), $report), 403);

            return view('einsatzbericht::pages.situation', compact('report'));
        })->whereNumber('report')->name('situation');

        // The QR code big, for others to scan from this screen
        Route::get('/{report}/qr', function (Report $report) {
            abort_unless(ReportService::canLead(auth()->user()), 403);

            return view('einsatzbericht::pages.qr', ['report' => $report, 'qr' => ExternalAccess::qr($report, 600), 'url' => ExternalAccess::url($report)]);
        })->whereNumber('report')->name('qr');

        Route::get('/lagefotos/{photo}', function (SituationPhoto $photo) {
            abort_unless(SituationService::canSee(auth()->user(), $photo->message->report), 403);

            return response(base64_decode($photo->data), 200, ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, max-age=86400']);
        })->whereNumber('photo')->name('photo');

        // The material catalog is kept by the admins
        Route::get('/material', fn () => view('einsatzbericht::pages.manage', ['livewireComponent' => 'einsatzbericht.material-catalog', 'title' => 'Material-Katalog']))
            ->middleware('can:einsatzbericht-materials')->name('materials');

        // The full report only for Einsatzleiter and Wehrführung (data of third parties)
        Route::middleware('can:einsatzbericht-lead')->group(function () {
            Route::get('/{report}', fn (Report $report) => view('einsatzbericht::pages.report', compact('report')))->whereNumber('report')->name('show');
            Route::get('/{report}/druck', fn (Report $report) => view('einsatzbericht::pages.print', [
                'report' => $report->load(['vehicles.crew.user', 'commander', 'closer', 'releaser', 'materials', 'messages.user', 'messages.photos', 'messages.externalUnit', 'externalUnits.statuses']),
                'strength' => ReportService::reportStrength($report),
            ]))->whereNumber('report')->name('print');
        });

        Route::middleware('can:einsatzbericht-release')->group(function () {
            Route::get('/statistik', fn () => view('einsatzbericht::pages.manage', ['livewireComponent' => 'einsatzbericht.statistics', 'title' => 'Jahresstatistik']))->name('statistics');
            Route::get('/einstellungen', fn () => view('einsatzbericht::pages.manage', ['livewireComponent' => 'einsatzbericht.settings', 'title' => 'Einstellungen Einsatzbericht']))->name('settings');
            Route::get('/kameraden', fn () => view('einsatzbericht::pages.manage', ['livewireComponent' => 'einsatzbericht.members', 'title' => 'Einsätze der Kameraden']))->name('members');
            Route::get('/kameraden/{user}', fn (User $user) => view('einsatzbericht::pages.member', compact('user')))->whereNumber('user')->name('member');
        });
    });
});
