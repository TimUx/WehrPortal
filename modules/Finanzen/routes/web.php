<?php

use App\Support\FileResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Models\Receipt;
use Modules\Finanzen\src\Services\FinanceExport;
use Modules\Finanzen\src\Services\FinanceService;
use Symfony\Component\HttpFoundation\HeaderUtils;

Route::prefix('finanzen')->name('finanzen.')
    ->middleware(['auth', 'active', 'module:finanzen'])
    ->group(function () {
        Route::get('/', fn () => view('finanzen::pages.index'))->name('index');
        Route::get('/einreichen', fn () => view('finanzen::pages.form', ['entry' => null, 'direct' => false]))->name('submit');

        Route::get('/buchung/{entry}', function (Entry $entry) {
            abort_unless(FinanceService::canView(auth()->user(), $entry), 403);

            return view('finanzen::pages.entry', compact('entry'));
        })->whereNumber('entry')->name('entry');

        Route::get('/buchung/{entry}/bearbeiten', function (Entry $entry) {
            abort_unless(FinanceService::canEdit(auth()->user(), $entry), 403);

            return view('finanzen::pages.form', ['entry' => $entry, 'direct' => false]);
        })->whereNumber('entry')->name('edit');

        // A Beleg: only for whoever may see the Buchung; never cached by shared caches
        Route::get('/beleg/{receipt}', function (Receipt $receipt) {
            abort_unless(FinanceService::canView(auth()->user(), $receipt->entry), 403);

            $response = FileResponse::make(base64_decode($receipt->data), $receipt->name);
            if (request()->boolean('download')) {
                $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition('attachment', $receipt->name, Str::ascii($receipt->name) ?: 'beleg'));
            }

            return $response;
        })->whereNumber('receipt')->name('receipt');

        // Kassenwart, Wehrführung and admins: all Buchungen, Jahresabschluss and export
        Route::middleware('can:finanzen-review')->group(function () {
            Route::get('/buchungen', fn () => view('finanzen::pages.entries'))->name('entries');
            Route::get('/jahresabschluss', fn () => view('finanzen::pages.report'))->name('report');

            Route::get('/jahresabschluss/{year}/druck', function (int $year, FinanceService $finance, FinanceExport $export) {
                $overview = $finance->overview($year, activeOnly: true);

                return view('finanzen::pages.print', [
                    'year' => $year, 'overview' => $overview, 'totals' => $finance->totals($overview),
                    'entries' => $export->entries($year), 'closed' => FinanceService::isClosed($year),
                ]);
            })->whereNumber('year')->name('print');

            Route::get('/export/{year}/buchungen.csv', fn (int $year, FinanceExport $export) => response($export->csv($year), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Buchungen-'.$year.'.csv"',
            ]))->whereNumber('year')->name('export.csv');

            Route::get('/export/{year}/belege.zip', fn (int $year, FinanceExport $export) => response()
                ->download($export->receiptsZip($year), 'Belege-'.$year.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend())
                ->whereNumber('year')->name('export.zip');
        });

        // Kassenwart and admins: book directly, Kostenstellen and budgets
        Route::middleware('can:finanzen-manage')->group(function () {
            Route::get('/buchen', fn () => view('finanzen::pages.form', ['entry' => null, 'direct' => true]))->name('book');
            Route::get('/budget', fn () => view('finanzen::pages.budget'))->name('budget');
        });
    });
