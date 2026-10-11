<?php

use Illuminate\Support\Facades\Route;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Models\Attachment;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Services\WartungPdf;

Route::prefix('wartung')->name('wartung.')
    ->middleware(['auth', 'active', 'module:wartung'])
    ->group(function () {
        Route::get('/', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.overview', 'title' => 'Wartungsmanagement']))->name('index');
        Route::get('/protokolle', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.protocol-list', 'title' => 'Prüfprotokolle']))->name('protocols');
        Route::get('/protokolle/{protocol}', fn (Protocol $protocol) => view('wartung::pages.page', ['livewireComponent' => 'wartung.protocol-detail', 'title' => 'Prüfprotokoll '.$protocol->code(), 'params' => ['protocol' => $protocol]]))
            ->whereNumber('protocol')->name('protocol');

        Route::get('/protokolle/{protocol}/pdf', fn (Protocol $protocol, WartungPdf $pdf) => response($pdf->protocol($protocol), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Pruefprotokoll-'.$protocol->code().'.pdf"',
        ]))->whereNumber('protocol')->name('protocol.pdf');

        Route::get('/anhaenge/{attachment}', fn (Attachment $attachment) => response(base64_decode($attachment->data), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]))->whereNumber('attachment')->name('attachment');

        Route::get('/geraete/{equipment}', fn (Equipment $equipment) => view('wartung::pages.page', ['livewireComponent' => 'wartung.device-detail', 'title' => $equipment->name, 'params' => ['equipment' => $equipment]]))
            ->whereNumber('equipment')->name('device');

        // Gerätewarte and Prüfer: carry out a Wartung (from the list, a device, or ad hoc)
        Route::get('/durchfuehren', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.protocol-form', 'title' => 'Wartung durchführen']))
            ->middleware('can:wartung-perform')->name('perform');

        Route::get('/vorlagen/{template}/pdf', fn (Template $template, WartungPdf $pdf) => response($pdf->template($template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Checkliste-'.str($template->name)->slug().'.pdf"',
        ]))->whereNumber('template')->name('template.pdf');

        // Gerätewarte and admins: templates, Fachfirmen, Prüfer
        Route::middleware('can:wartung-manage')->group(function () {
            Route::get('/vorlagen', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.template-list', 'title' => 'Wartungsvorlagen']))->name('templates');
            Route::get('/vorlagen/neu', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.template-form', 'title' => 'Neue Wartungsvorlage']))->name('template.create');
            Route::get('/vorlagen/{template}', fn (Template $template) => view('wartung::pages.page', ['livewireComponent' => 'wartung.template-form', 'title' => $template->name, 'params' => ['template' => $template]]))
                ->whereNumber('template')->name('template');
            Route::get('/fachfirmen', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.providers', 'title' => 'Fachfirmen']))->name('providers');
            Route::get('/pruefer', fn () => view('wartung::pages.page', ['livewireComponent' => 'wartung.inspectors', 'title' => 'Prüfer & Qualifikationen']))->name('inspectors');
        });
    });
