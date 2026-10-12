<?php

use Illuminate\Support\Facades\Route;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Models\Version;
use Modules\Objektplaene\src\Services\ObjectPlanService;

Route::prefix('objektplaene')->name('objektplaene.')
    ->middleware(['auth', 'active', 'module:objektplaene'])
    ->group(function () {
        $page = fn (string $component, string $title, array $params = []) => view('objektplaene::pages.page', ['livewireComponent' => $component, 'title' => $title, 'params' => $params]);

        Route::get('/', fn () => $page('objektplaene.library', 'Objektpläne'))->name('index');
        Route::get('/objekt/{object}', fn (PlanObject $object) => $page('objektplaene.object-detail', $object->name, ['object' => $object]))->whereNumber('object')->name('object');

        // Versions never change, so the browser (and the offline cache) may keep them; earlier ones only for Planverwalter
        Route::get('/datei/{version}', function (Version $version) {
            $current = (int) Version::where('document_id', $version->document_id)->max('version');
            abort_unless($version->version === $current || ObjectPlanService::canManage(auth()->user()), 403);
            abort_unless($version->document?->object?->is_active || ObjectPlanService::canManage(auth()->user()), 404);

            return response(base64_decode($version->data), 200, [
                'Content-Type' => $version->mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($version->name).'"',
                'Cache-Control' => 'private, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        })->whereNumber('version')->name('file');

        // The service worker for "Für offline speichern"; its scope is the whole module
        Route::get('/sw.js', fn () => response(file_get_contents(dirname(__DIR__).'/resources/js/sw.js'), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/objektplaene',
        ]))->name('worker');

        Route::middleware('can:objektplaene-manage')->group(function () use ($page) {
            Route::get('/neu', fn () => $page('objektplaene.object-form', 'Neues Objekt'))->name('create');
            Route::get('/objekt/{object}/bearbeiten', fn (PlanObject $object) => $page('objektplaene.object-form', $object->name.' bearbeiten', ['object' => $object]))
                ->whereNumber('object')->name('edit');
        });
    });
