<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\PlanService;

Route::prefix('schichtplan')->name('schichtplan.')
    ->middleware(['auth', 'active', 'module:schichtplan'])
    ->group(function () {
        Route::get('/', fn () => view('schichtplan::pages.index'))->name('index');

        Route::get('/neu', fn () => view('schichtplan::pages.create'))
            ->middleware('can:manage,'.Plan::class)->name('create');

        Route::get('/{plan}', fn (Plan $plan) => view('schichtplan::pages.show', compact('plan')))
            ->whereNumber('plan')->middleware('can:view,plan')->name('show');

        Route::get('/{plan}/bearbeiten', fn (Plan $plan) => view('schichtplan::pages.edit', compact('plan')))
            ->whereNumber('plan')->middleware('can:manage,plan')->name('edit');

        Route::get('/{plan}/schichtplan.csv', fn (Plan $plan, PlanService $plans) => response($plans->csv($plan), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="schichtplan-'.Str::slug($plan->title).'.csv"',
        ]))->whereNumber('plan')->middleware('can:view,plan')->name('csv');

        Route::get('/{plan}/druck', fn (Plan $plan) => view('schichtplan::pages.print', [
            'plan' => $plan->load(['stations.lead', 'slots']),
            'assignments' => $plan->assignments()->with('user')->get(),
            'people' => $plan->participants()->with('user')->get()->pluck('user')
                ->concat($plan->stations->pluck('lead'))->filter()->unique('id')->sortBy('name'),
        ]))->whereNumber('plan')->middleware('can:view,plan')->name('print');
    });
