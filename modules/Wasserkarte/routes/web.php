<?php

use Illuminate\Support\Facades\Route;
use Modules\Wasserkarte\src\Models\Defect;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\WaterService;

Route::prefix('wasserkarte')->name('wasserkarte.')
    ->middleware(['auth', 'active', 'module:wasserkarte'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('wasserkarte::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        // The map; ?einsatz= centres on an Einsatzstelle and highlights the nearest sources
        Route::get('/', fn () => $page('wasserkarte.map-view', 'Wasserkarte'))->name('index');
        Route::get('/quelle/{source}', fn (Source $source) => $page('wasserkarte.source-detail', $source->label(), ['source' => $source]))->whereNumber('source')->name('source');

        Route::get('/maengel/{defect}/foto', function (Defect $defect) {
            abort_unless($defect->photo, 404);

            return response(base64_decode($defect->photo), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
        })->whereNumber('defect')->name('defect.photo');

        Route::middleware('can:wasserkarte-manage')->group(function () use ($page) {
            Route::get('/neu', fn () => $page('wasserkarte.source-form', 'Neue Wasserquelle'))->name('create');
            Route::get('/quelle/{source}/bearbeiten', fn (Source $source) => $page('wasserkarte.source-form', $source->label().' bearbeiten', ['source' => $source]))
                ->whereNumber('source')->name('edit');
            Route::get('/pruefungen', fn () => $page('wasserkarte.inspections', 'Prüfungen & Import'))->name('inspections');
            Route::get('/pruefungen.csv', fn (WaterService $water) => response($water->inspectionCsv(), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Wasserentnahmestellen-'.now('Europe/Berlin')->format('Y-m-d').'.csv"',
            ]))->name('inspections.csv');
            // A label with QR code for the source, to fix at the Saugstelle or the Hydrantenschild
            Route::get('/quelle/{source}/etikett', fn (Source $source) => view('wasserkarte::pages.label', ['source' => $source]))->whereNumber('source')->name('label');
        });
    });
