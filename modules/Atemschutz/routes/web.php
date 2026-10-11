<?php

use Illuminate\Support\Facades\Route;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Services\AtemschutzPdf;
use Modules\Atemschutz\src\Services\MonitorService;

Route::middleware(['auth', 'active', 'module:atemschutz'])->group(function () {
    // Personalakte → Atemschutz: the own status, deadlines and Einsätze under Atemschutz
    Route::get('/personalakte/atemschutz', fn () => view('atemschutz::pages.personnel-file'))->name('atemschutz.personnel-file');

    Route::prefix('atemschutz')->name('atemschutz.')->group(function () {
        Route::get('/', fn () => view('atemschutz::pages.page', ['livewireComponent' => 'atemschutz.overview', 'title' => 'Atemschutzüberwachung']))->name('index');

        // The board of an Überwachung; ended ones stay readable for the Überwacher
        Route::get('/ueberwachung/{operation}', fn (Operation $operation) => view('atemschutz::pages.board', compact('operation')))
            ->whereNumber('operation')->middleware('can:atemschutz-monitor')->name('board');

        Route::get('/ueberwachung/{operation}/pdf', function (Operation $operation, AtemschutzPdf $pdf) {
            abort_unless(MonitorService::canMonitor(auth()->user()) || auth()->user()->can('einsatzbericht-lead'), 403);

            return response($pdf->operation($operation), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Atemschutzueberwachung-'.$operation->started_at->setTimezone('Europe/Berlin')->format('Y-m-d-Hi').'.pdf"',
            ]);
        })->whereNumber('operation')->name('pdf');

        Route::get('/geraetetraeger', fn () => view('atemschutz::pages.page', ['livewireComponent' => 'atemschutz.wearers', 'title' => 'Atemschutzgeräteträger']))
            ->middleware('can:atemschutz-manage')->name('wearers');
    });
});
