<?php

use Illuminate\Support\Facades\Route;
use Modules\Gefahrstoffe\src\Models\Material;

Route::prefix('gefahrstoffe')->name('gefahrstoffe.')
    ->middleware(['auth', 'active', 'module:gefahrstoffe'])
    ->group(function () {
        Route::view('/', 'gefahrstoffe::pages.index')->name('index');

        Route::get('/katalog.json', fn () => response()->json([
            'materials' => Material::query()->orderBy('un_number')->get()->map(fn (Material $material) => $material->catalogueEntry())->values(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store'))->name('catalogue');

        Route::get('/import', fn () => view('gefahrstoffe::pages.import'))
            ->middleware('can:gefahrstoffe-manage')->name('import');
    });
