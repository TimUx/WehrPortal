<?php

use App\Support\FileResponse;
use Extensions\Anwesenheit\Models\AttendanceFile;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendancePdf;
use Extensions\Anwesenheit\Services\AttendanceService;
use Illuminate\Support\Facades\Route;

Route::prefix('anwesenheit')->name('anwesenheit.')
    ->middleware(['auth', 'active', 'module:anwesenheit'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('anwesenheit::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        Route::get('/', fn () => $page('anwesenheit.list-index', 'Anwesenheitslisten'))->name('index');
        Route::get('/neu', fn () => $page('anwesenheit.list-form', 'Neue Anwesenheitsliste'))->name('create');
        Route::get('/{list}', function (AttendanceList $list) use ($page) {
            return $page('anwesenheit.list-show', $list->title, ['list' => $list]);
        })->whereNumber('list')->name('show');
        Route::get('/{list}/bearbeiten', function (AttendanceList $list) use ($page) {
            abort_unless(AttendanceService::canEdit(auth()->user(), $list), 403);

            return $page('anwesenheit.list-form', 'Anwesenheitsliste bearbeiten', ['list' => $list]);
        })->whereNumber('list')->name('edit');

        Route::get('/{list}/pdf', function (AttendanceList $list, AttendancePdf $pdf) {
            abort_unless(AttendanceService::canSee(auth()->user(), $list), 403);

            return FileResponse::make($pdf->render($list), AttendancePdf::filename($list));
        })->whereNumber('list')->name('pdf');

        Route::get('/{list}/datei/{file}', function (AttendanceList $list, int $file) {
            abort_unless(AttendanceService::canSee(auth()->user(), $list), 403);
            $file = AttendanceFile::where('list_id', $list->id)->findOrFail($file);

            return FileResponse::make($file->bytes(), $file->filename);
        })->whereNumber(['list', 'file'])->name('file');
    });
