<?php

use App\Models\User;
use App\Support\Csv;
use Illuminate\Support\Facades\Route;
use Modules\Lehrgaenge\src\Livewire\Qualifications;
use Modules\Lehrgaenge\src\Support\CoursePlan;

// Personalakte → Lehrgänge: the own absolvierte and coming Lehrgänge
Route::get('/personalakte/lehrgaenge', fn () => view('lehrgaenge::pages.personnel-file'))
    ->middleware(['auth', 'active', 'module:lehrgaenge'])->name('lehrgaenge.personnel-file');

Route::prefix('lehrgaenge')->name('lehrgaenge.')
    ->middleware(['auth', 'active', 'module:lehrgaenge'])
    ->group(function () {
        Route::get('/', fn () => view('lehrgaenge::pages.index'))->name('index');

        Route::get('/anmeldungen', fn () => view('lehrgaenge::pages.registrations'))->middleware('can:manage-lehrgaenge')->name('registrations');
        Route::get('/lehrgangsliste', fn () => view('lehrgaenge::pages.courses'))->middleware('can:manage-lehrgaenge')->name('courses');
        Route::get('/import', fn () => view('lehrgaenge::pages.page', ['livewireComponent' => 'lehrgaenge.result-imports', 'title' => 'Ergebnisse importieren']))
            ->middleware('can:manage-lehrgaenge')->name('import');

        // Ausbildungsleiter and Wehrführung see which Lehrgänge a Kamerad has absolviert
        Route::middleware('can:view-lehrgaenge')->group(function () {
            Route::get('/plan', function () {
                $year = request()->filled('jahr') ? max(2000, min(2100, request()->integer('jahr'))) : null;

                return view('lehrgaenge::pages.plan', ['year' => $year, 'months' => CoursePlan::rows($year)]);
            })->name('plan');
            Route::get('/ausbildungsstand', fn () => view('lehrgaenge::pages.page', ['livewireComponent' => 'lehrgaenge.qualifications', 'title' => 'Ausbildungsstand']))->name('qualifications');
            Route::get('/ausbildungsstand.csv', function () {
                $t = Qualifications::table(request()->integer('abteilung') ?: null);
                $out = fopen('php://temp', 'r+');
                fwrite($out, "\xEF\xBB\xBF");
                Csv::put($out, ['Kamerad', ...$t['courses']->pluck('name')->all()], ';');
                foreach ($t['members'] as $m) {
                    Csv::put($out, [$m->name, ...$t['courses']->map(function ($c) use ($t, $m) {
                        $r = $t['cells'][$m->id.'-'.$c->id] ?? null;

                        return $r ? $r->completed_on?->format('d.m.Y').($r->refreshState() === 'expired' ? ' (Auffrischung überfällig)' : '') : '';
                    })->all()], ';');
                }
                rewind($out);

                return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="Ausbildungsstand.csv"']);
            })->name('qualifications.csv');
            Route::get('/kameraden', fn () => view('lehrgaenge::pages.members'))->name('members');
            Route::get('/kameraden/{user}', fn (User $user) => view('lehrgaenge::pages.member', compact('user')))->whereNumber('user')->name('member');
        });
    });
