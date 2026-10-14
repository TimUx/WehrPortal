<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\CreateItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;

// No Filament::setCurrentPanel() here: /i/{code} is outside every panel, exactly like a real scan.
beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->kleiderwart = User::factory()->create();
    $this->kleiderwart->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
});

test('scanning a known code redirects to the item in the Kleiderkammer panel', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    $this->actingAs($this->kleiderwart)
        ->get('/i/WEHREJA0001')
        ->assertRedirect(url("/admin/kleiderkammer/einzelteile/{$item->id}/bearbeiten"));
});

test('scanning an unknown code redirects to the create form in the Kleiderkammer panel', function () {
    $this->actingAs($this->kleiderwart)
        ->get('/i/WEHREJA0099')
        ->assertRedirect(url('/admin/kleiderkammer/einzelteile/neu?code=WEHREJA0099'));
});

test('the item form section spans the full width', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $section = Livewire::actingAs($this->kleiderwart)
        ->test(CreateItem::class)
        ->instance()
        ->form
        ->getComponents()[0];

    expect($section->getColumnSpan('default'))->toBe('full');
});
