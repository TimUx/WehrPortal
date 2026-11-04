<?php

use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Support\Facades\Route;
use Modules\Atemschutz\src\Models\EquipmentDocument;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\WearerQualification;
use Modules\Atemschutz\src\Services\AtemschutzPdf;
use Modules\Atemschutz\src\Services\MonitorService;

Route::middleware(['auth', 'active', 'module:atemschutz'])->group(function () {
    Route::get('/atemschutz/ausruestung/dokumente/{document}', function (EquipmentDocument $document) {
        abort_unless(MonitorService::canManageEquipment(auth()->user()), 403);
        $bytes = base64_decode($document->data, true);
        abort_if($bytes === false, 404);

        return FileResponse::make($bytes, $document->filename);
    })->whereNumber('document')->name('atemschutz.equipment-document');

    Route::get('/atemschutz/geraetetraeger/qualifikationen/{qualification}/beleg', function (WearerQualification $qualification) {
        $actor = auth()->user();
        abort_unless($actor instanceof User
            && MonitorService::canCareFor($actor, $qualification->wearer->user_id), 403);
        $bytes = base64_decode((string) $qualification->proof_data, true);
        abort_if($bytes === false, 404);

        return FileResponse::make($bytes, $qualification->proof_filename);
    })->whereNumber('qualification')->name('atemschutz.qualification-proof');

    // Personalakte → Atemschutz: the own status, deadlines and Einsätze under Atemschutz
    Route::get('/personalakte/atemschutz', fn () => view('atemschutz::pages.personnel-file'))->name('atemschutz.personnel-file');

    Route::prefix('atemschutz')->name('atemschutz.')->group(function () {
        Route::get('/', fn () => view('atemschutz::pages.page', ['livewireComponent' => 'atemschutz.overview', 'title' => 'Atemschutzüberwachung']))->name('index');

        // The board of an Überwachung; ended ones stay readable for the Überwacher
        Route::get('/ueberwachung/{operation}', function (Operation $operation) {
            abort_unless(MonitorService::canMonitorOperation(auth()->user(), $operation), 403);

            return view('atemschutz::pages.board', compact('operation'));
        })->whereNumber('operation')->middleware('can:atemschutz-monitor')->name('board');

        Route::get('/ueberwachung/{operation}/pdf', function (Operation $operation, AtemschutzPdf $pdf) {
            abort_unless(MonitorService::canMonitorOperation(auth()->user(), $operation) || auth()->user()->can('einsatzbericht-lead'), 403);

            return response($pdf->operation($operation), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Atemschutzueberwachung-'.$operation->started_at->setTimezone('Europe/Berlin')->format('Y-m-d-Hi').'.pdf"',
            ]);
        })->whereNumber('operation')->name('pdf');
    });
});
