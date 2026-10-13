<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Expositionen\src\Livewire\Manage;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

Route::middleware(['auth', 'active', 'module:expositionen'])->group(function () {
    $page = fn (string $component, string $title, array $params = []) => view('expositionen::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

    // Personalakte → Expositionen: the own ones
    Route::get('/personalakte/expositionen', fn () => view('expositionen::pages.personnel-file'))->name('expositionen.personnel-file');

    Route::prefix('expositionen')->name('expositionen.')->group(function () use ($page) {
        Route::get('/', fn () => $page('expositionen.my-exposures', 'Meine Expositionen'))->name('index');
        Route::get('/erfassen', fn () => $page('expositionen.exposure-form', 'Exposition erfassen'))->name('create');
        Route::get('/{exposure}', fn (Exposure $exposure) => $page('expositionen.exposure-form', 'Exposition', ['exposure' => $exposure]))->whereNumber('exposure')->name('edit');

        // From the Einsatzbericht: for the crew
        Route::get('/einsatz/{report}', function (Report $report) use ($page) {
            abort_unless(ExposureService::canPropose(auth()->user()), 403);

            return $page('expositionen.crew-form', 'Exposition für die Besatzung', ['report' => $report]);
        })->whereNumber('report')->name('crew');

        // Expositionsnachweis of a Kamerad: for themselves (e.g. when leaving) and the Beauftragte
        Route::get('/nachweis/{user}', function (User $user) {
            abort_unless($user->is(auth()->user()) || ExposureService::canManage(auth()->user()), 403);

            return view('expositionen::pages.certificate', ['member' => $user, 'exposures' => Exposure::where('user_id', $user->id)->where('status', '!=', 'draft')->orderBy('occurred_on')->get()]);
        })->whereNumber('user')->name('certificate');

        Route::middleware('can:expositionen-manage')->group(function () use ($page) {
            Route::get('/verwaltung', fn () => $page('expositionen.manage', 'Expositionen – Meldeliste'))->name('manage');
            Route::get('/verwaltung.csv', function (ExposureService $service) {
                $manage = new Manage;
                $manage->status = (string) request('status', '');
                $manage->year = (string) request('year', '');

                return response($service->csv($manage->query()->get()), 200, [
                    'Content-Type' => 'text/csv; charset=utf-8',
                    'Content-Disposition' => 'attachment; filename="Expositionen-'.now('Europe/Berlin')->format('Y-m-d').'.csv"',
                ]);
            })->name('csv');
        });
    });
});
