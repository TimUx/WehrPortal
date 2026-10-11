<?php

use App\Filament\Pages\ManageModules;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the modules page sorts by name, searches and filters', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->admin()->create();
    Module::query()->update(['is_active' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    User::factory()->create()->moduleAccess()->create(['module' => 'kleiderkammer']);

    $page = Livewire::actingAs($admin)->test(ManageModules::class);
    $names = fn ($component) => collect($component->instance()->getTableRecords())
        ->map(fn (Module $m) => app(ModuleRegistry::class)->get($m->key)['name'])->values()->all();

    $sorted = $names($page);
    expect($sorted)->toBe(collect($sorted)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all())
        ->and(count($sorted))->toBe(count(app(ModuleRegistry::class)->all()));

    $page->searchTable('kleider');
    expect($names($page))->toBe(['Kleiderkammer']);

    $page->searchTable('')->filterTable('is_active', true);
    expect($names($page))->toBe(['Kleiderkammer']);
    $page->assertTableColumnStateSet('users_count', 1, Module::find('kleiderkammer'));

    $page->resetTableFilters()->sortTable('module_name', 'desc');
    expect($names($page))->toBe(array_reverse($sorted));
});

test('the page is called Module verwalten and active module names link to the module', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Module::where('key', 'schichtplan')->update(['is_active' => false]);

    expect(ManageModules::getNavigationLabel())->toBe('Module');

    $page = Livewire::actingAs(User::factory()->admin()->create())->test(ManageModules::class)
        ->assertSee('Module verwalten')
        ->assertDontSee('Manage Modules');

    $column = $page->instance()->getTable()->getColumn('module_name');
    expect($column->record(Module::find('kleiderkammer'))->getUrl())->toBe(route('kleiderkammer.index'))
        ->and($column->record(Module::find('schichtplan'))->getUrl())->toBeNull();
});
