<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages\ListItemCategories;
use Modules\Kleiderkammer\src\Models\ItemCategory;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
});

test('categories can be reordered by drag and drop', function () {
    $a = ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);
    $b = ItemCategory::create(['name' => 'Dienstkleidung', 'sort_order' => 2]);
    $c = ItemCategory::create(['name' => 'Sonstiges', 'sort_order' => 3]);

    Livewire::test(ListItemCategories::class)
        ->call('toggleTableReordering')
        ->call('reorderTable', [(string) $c->id, (string) $a->id, (string) $b->id]);

    expect(ItemCategory::orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Sonstiges', 'Schutzkleidung', 'Dienstkleidung']);
});

test('category list shows a labelled reorder button', function () {
    ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);

    Livewire::test(ListItemCategories::class)
        ->assertSee('Reihenfolge ändern')
        ->call('toggleTableReordering')
        ->assertSee('Fertig');
});
