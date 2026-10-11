<?php

use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Models\Group;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
});

test('groups can be reordered by drag and drop', function () {
    Group::query()->delete();
    $a = Group::create(['name' => 'Aktive', 'sort_order' => 1]);
    $b = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $c = Group::create(['name' => 'Musikzug', 'sort_order' => 3]);

    Livewire::test(ListGroups::class)
        ->call('toggleTableReordering')
        ->call('reorderTable', [(string) $c->id, (string) $a->id, (string) $b->id]);

    expect(Group::orderBy('sort_order')->pluck('name')->all())->toBe(['Musikzug', 'Aktive', 'Jugendfeuerwehr']);
});

test('the group list shows a labelled reorder button', function () {
    Livewire::test(ListGroups::class)
        ->assertSee('Reihenfolge ändern')
        ->call('toggleTableReordering')
        ->assertSee('Fertig');
});
