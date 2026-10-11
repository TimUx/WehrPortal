<?php

use App\Models\Module;
use App\Models\User;
use App\Support\BrandColor;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Cache;

test('all panels use exactly the brand red as primary colour', function (string $panel) {
    $primary = Filament::getPanel($panel)->getColors()['primary'];

    // OKLCH rounding turns #ED2F12 into #ED2F11; the buttons get the exact value via brand-buttons.blade.php.
    expect($primary[600])->toBe(Color::convertToOklch(BrandColor::RED));
    expect($primary[500])->toBe(Color::convertToOklch(BrandColor::RED));
})->with(['admin', 'fahrzeugbuchung', 'kleiderkammer']);

test('admin panels force white text on red primary buttons', function (string $path) {
    Module::query()->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->actingAs(User::factory()->admin()->create())
        ->get($path)
        ->assertOk()
        ->assertSee('.fi-btn.fi-color-primary:not(.fi-outlined)', false)
        ->assertSee('background-color: '.BrandColor::RED, false);
})->with(['/admin', '/admin/fahrzeugbuchung', '/admin/kleiderkammer']);
