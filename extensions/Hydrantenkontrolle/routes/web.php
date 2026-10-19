<?php

use App\Support\FileResponse;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Services\InspectionPdf;
use Illuminate\Support\Facades\Route;

Route::prefix('hydrantenkontrolle')->name('hydrantenkontrolle.')
    ->middleware(['auth', 'active', 'module:hydrantenkontrolle', 'can:manage-hydrantenkontrolle'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('hydrantenkontrolle::pages.page', [
            'livewireComponent' => 'hydrantenkontrolle.'.$component, 'title' => $title, 'params' => $params,
        ]);

        Route::get('/', fn () => $page('year-overview', 'Hydrantenkontrolle'))->name('index');
        Route::get('/hydranten', fn () => $page('hydrant-index', 'Hydranten'))->name('hydrants');
        Route::get('/hydranten/neu', fn () => $page('hydrant-form', 'Hydrant erfassen'))->name('create');
        Route::get('/hydranten/{hydrant}/historie', fn (Hydrant $hydrant) => $page('hydrant-history', 'Kontrollhistorie', ['hydrant' => $hydrant]))
            ->whereNumber('hydrant')->name('history');
        Route::get('/hydranten/{hydrant}/bearbeiten', fn (Hydrant $hydrant) => $page('hydrant-form', 'Hydrant bearbeiten', ['hydrant' => $hydrant]))
            ->whereNumber('hydrant')->name('edit');
        Route::get('/jahr/{year}', fn (InspectionYear $year) => $page('year-overview', 'Hydrantenkontrolle '.$year->year, ['year' => $year]))
            ->whereNumber('year')->name('year');
        Route::get('/jahr/{year}/pdf', function (InspectionYear $year, InspectionPdf $pdf) {
            return FileResponse::make($pdf->render($year), 'Hydrantenkontrolle-'.$year->year.'.pdf', 'private, no-store');
        })->whereNumber('year')->name('pdf');
    });
