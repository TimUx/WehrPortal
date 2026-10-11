<?php

use Illuminate\Support\Facades\Route;

Route::prefix('kleiderkammer')->name('kleiderkammer.')
    ->middleware(['auth', 'active', 'module:kleiderkammer'])
    ->group(function () {
        Route::get('/', function () {
            $items = auth()->user()->kleiderkammerItems()
                ->with(['item.itemType', 'item.size', 'item.group', 'itemType', 'size', 'group'])
                ->orderByDesc('issued_at')
                ->get();

            return view('kleiderkammer::pages.my-equipment', compact('items'));
        })->name('index');
    });
