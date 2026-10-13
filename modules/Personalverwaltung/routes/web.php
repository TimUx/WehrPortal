<?php

use App\Livewire\Profile\Hours;
use App\Models\User;
use App\Services\HoursRegistry;
use App\Support\HoursCsv;
use Illuminate\Support\Facades\Route;
use Modules\Personalverwaltung\src\Livewire\Reports;
use Modules\Personalverwaltung\src\Models\HistoryFile;
use Modules\Personalverwaltung\src\Services\PersonnelService;

Route::middleware(['auth', 'active', 'module:personalverwaltung'])->group(function () {
    // Personalakte → Laufbahn & Ehrungen: every Kamerad with access sees their own
    Route::get('/personalakte/laufbahn', fn () => view('personalverwaltung::pages.own-file'))->name('personalverwaltung.own');
    // Personalakte → Persönliche Daten: self-service, while the Personalverwalter allow it
    // A Nachweis of the Diensthistorie: for the Personalverwalter of the Kamerad and the Kamerad
    Route::get('/personalakte/nachweis/{file}', function (HistoryFile $file) {
        $owner = $file->entry->user;
        abort_unless($owner->is(auth()->user()) || (PersonnelService::canManage(auth()->user()) && PersonnelService::mayView(auth()->user(), $owner)), 403);
        $data = HistoryFile::whereKey($file->id)->value('data');

        return response(base64_decode($data), 200, [
            'Content-Type' => $file->mime, 'Content-Disposition' => 'inline; filename="'.addcslashes($file->filename, '"\\').'"', 'Cache-Control' => 'private, max-age=3600',
        ]);
    })->whereNumber('file')->name('personalverwaltung.history-file');
    Route::get('/personalakte/persoenliche-daten', fn () => view('personalverwaltung::pages.own-data'))->name('personalverwaltung.own-data');

    Route::prefix('personalverwaltung')->name('personalverwaltung.')->middleware('can:personalverwaltung-view')->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('personalverwaltung::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        Route::get('/', fn () => $page('personalverwaltung.member-list', 'Personalverwaltung'))->name('index');
        Route::get('/kamerad/{member}', fn (User $member) => $page('personalverwaltung.member-file', $member->name, ['member' => $member]))->whereNumber('member')->name('member');
        Route::get('/aenderungswuensche', fn () => $page('personalverwaltung.change-requests', 'Änderungswünsche'))->name('requests')->middleware('can:personalverwaltung-manage');
        Route::get('/auswertungen', fn () => $page('personalverwaltung.reports', 'Auswertungen'))->name('reports');
        Route::get('/auswertungen.csv', function (PersonnelService $personnel) {
            $kind = array_key_exists((string) request('art'), PersonnelService::REPORTS) ? (string) request('art') : 'funktionen';
            $rows = $personnel->report(auth()->user(), $kind, ...Reports::period((string) request('von'), (string) request('bis')));
            $out = fopen('php://temp', 'r+');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [PersonnelService::REPORTS[$kind], 'Name', 'Angabe', 'Datum'], ';');
            foreach ($rows as $row) {
                fputcsv($out, [$row['group'], $row['name'], $row['detail'], $row['date']?->format('d.m.Y')], ';');
            }
            rewind($out);

            return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.PersonnelService::REPORTS[$kind].'.csv"']);
        })->name('reports.csv');
        Route::get('/stunden', fn () => $page('personalverwaltung.hours-overview', 'Stundennachweis'))->name('hours');
        Route::get('/stunden.csv', function (HoursRegistry $hours) {
            $year = max(2000, min(2100, request()->integer('jahr') ?: (int) now('Europe/Berlin')->year));
            $members = PersonnelService::visibleMembers(auth()->user())->where('status', 'active')->pluck('name', 'id')->all();

            return response(HoursCsv::make($hours->rows(array_keys($members), ...Hours::period($year)), $members), 200, [
                'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="Stundennachweis-'.$year.'.csv"',
            ]);
        })->name('hours.csv');
        Route::get('/neu', fn () => $page('personalverwaltung.new-members', 'Neue Mitglieder'))->middleware('can:personalverwaltung-manage')->name('new');
        Route::get('/ehrungen', fn () => $page('personalverwaltung.honors', 'Ehrungen'))->name('honors')->middleware('can:personalverwaltung-manage');
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
        Route::get('/einstellungen', fn () => $page('personalverwaltung.settings', 'Einstellungen'))->name('settings')->middleware('can:personalverwaltung-manage');
        Route::get('/adressliste.csv', function (PersonnelService $personnel) {
            abort_unless(PersonnelService::activeFields() !== [], 404);

            return response($personnel->addressCsv(auth()->user()), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Adressliste.csv"',
            ]);
        })->name('addresses')->middleware('can:personalverwaltung-manage');
        Route::get('/zugriff', fn () => $page('personalverwaltung.access', 'Zugriff'))->name('access')->middleware('can:personalverwaltung-manage');   // admins only, checked in the component
    });
});
