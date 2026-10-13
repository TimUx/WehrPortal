<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Personalverwaltung\src\Services\PersonnelService;

Route::middleware(['auth', 'active', 'module:personalverwaltung'])->group(function () {
    // Personalakte → Laufbahn & Ehrungen: every Kamerad with access sees their own
    Route::get('/personalakte/laufbahn', fn () => view('personalverwaltung::pages.own-file'))->name('personalverwaltung.own');
    // Personalakte → Persönliche Daten: self-service, while the Personalverwalter allow it
    Route::get('/personalakte/persoenliche-daten', fn () => view('personalverwaltung::pages.own-data'))->name('personalverwaltung.own-data');

    Route::prefix('personalverwaltung')->name('personalverwaltung.')->middleware('can:personalverwaltung-manage')->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('personalverwaltung::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        Route::get('/', fn () => $page('personalverwaltung.member-list', 'Personalverwaltung'))->name('index');
        Route::get('/kamerad/{member}', fn (User $member) => $page('personalverwaltung.member-file', $member->name, ['member' => $member]))->whereNumber('member')->name('member');
        Route::get('/aenderungswuensche', fn () => $page('personalverwaltung.change-requests', 'Änderungswünsche'))->name('requests');
        Route::get('/ehrungen', fn () => $page('personalverwaltung.honors', 'Ehrungen'))->name('honors');
        Route::get('/dienstbeteiligung', function () use ($page) {
            abort_unless(PersonnelService::attendanceAvailable(), 404);

            return $page('personalverwaltung.attendance', 'Dienstbeteiligung');
        })->name('attendance');
        Route::get('/dienstbeteiligung.csv', function (PersonnelService $personnel) {
            abort_unless(PersonnelService::attendanceAvailable(), 404);
            $year = (int) request('jahr', now('Europe/Berlin')->year);
            $types = (array) request('arten', PersonnelService::DUTY_TYPES);

            return response($personnel->attendanceCsv(auth()->user(), $year, $types), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Dienstbeteiligung-'.$year.'.csv"',
            ]);
        })->name('attendance.csv');
        // The admin area of the Personalverwalter (no admin rights needed)
        Route::get('/einstellungen', fn () => $page('personalverwaltung.settings', 'Einstellungen'))->name('settings');
        Route::get('/adressliste.csv', function (PersonnelService $personnel) {
            abort_unless(PersonnelService::activeFields() !== [], 404);

            return response($personnel->addressCsv(auth()->user()), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Adressliste.csv"',
            ]);
        })->name('addresses');
        Route::get('/zugriff', fn () => $page('personalverwaltung.access', 'Zugriff'))->name('access');   // admins only, checked in the component
    });
});
