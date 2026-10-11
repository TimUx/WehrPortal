<?php

use App\Models\Module;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages\CreateItem;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\EditItemType;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages\ListItemTypes;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers\GroupsRelationManager;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers\SizesRelationManager;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\IssuingService;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('kleiderkammer'));
    $this->actingAs(User::factory()->admin()->create());
});

function groupsManager(ItemType $type)
{
    return Livewire::test(GroupsRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditItemType::class]);
}

// ─── Kettensägenschutz ───────────────────────────────────────────────────────

test('item types can be marked as chainsaw protection and filtered by it', function () {
    $trousers = ItemType::create(['name' => 'Schnittschutzhose']);
    $jacket = ItemType::create(['name' => 'Einsatzjacke']);

    Livewire::test(EditItemType::class, ['record' => $trousers->getRouteKey()])
        ->fillForm(['is_chainsaw_protection' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($trousers->fresh()->is_chainsaw_protection)->toBeTrue();

    Livewire::test(ListItemTypes::class)
        ->filterTable('is_chainsaw_protection', true)
        ->assertCanSeeTableRecords([$trousers])
        ->assertCanNotSeeTableRecords([$jacket]);
});

// ─── Gruppen ─────────────────────────────────────────────────────────────────

test('groups are defined per item type', function () {
    $trousers = ItemType::create(['name' => 'Diensthose']);
    $ppe = ItemType::create(['name' => 'PSA-Jacke']);

    groupsManager($trousers)->callAction(TestAction::make('create')->table(), ['name' => 'Damen'])->assertHasNoFormErrors();
    groupsManager($trousers)->callAction(TestAction::make('create')->table(), ['name' => 'Herren'])->assertHasNoFormErrors();
    groupsManager($ppe)->callAction(TestAction::make('create')->table(), ['name' => 'Damen'])->assertHasNoFormErrors();

    expect($trousers->groups()->pluck('name')->all())->toBe(['Damen', 'Herren']);
    expect($ppe->groups()->pluck('name')->all())->toBe(['Damen']);

    groupsManager($trousers)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Damen'])
        ->assertHasFormErrors(['name' => 'unique']);
});

test('a group in use cannot be deleted', function () {
    $type = ItemType::create(['name' => 'PSA-Jacke', 'is_tracked' => true]);
    $sGuard = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'S-Guard']);
    Item::create(['item_type_id' => $type->id, 'group_id' => $sGuard->id, 'code' => 'WEHRPSA0001', 'status' => 'available']);

    groupsManager($type)->callAction(TestAction::make('delete')->table($sGuard));

    expect($sGuard->fresh())->not->toBeNull();
});

test('the item form only offers a group for item types that have groups', function () {
    $ppe = ItemType::create(['name' => 'PSA-Jacke', 'is_tracked' => true]);
    $helmet = ItemType::create(['name' => 'Helm', 'is_tracked' => true]);
    ItemGroup::create(['item_type_id' => $ppe->id, 'name' => 'Viking']);

    Livewire::test(CreateItem::class)
        ->fillForm(['item_type_id' => $helmet->id])
        ->assertFormFieldHidden('group_id')
        ->fillForm(['item_type_id' => $ppe->id])
        ->assertFormFieldVisible('group_id')
        ->fillForm(['code' => 'WEHRPSA0001', 'group_id' => $ppe->groups()->value('id')])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Item::where('code', 'WEHRPSA0001')->first()->group->name)->toBe('Viking');
});

test('stock is kept per group and returns go back to the right group', function () {
    $type = ItemType::create(['name' => 'Diensthose', 'is_tracked' => false]);
    $size = Size::create(['item_type_id' => $type->id, 'name' => '40']);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    $men = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Herren']);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'group_id' => $women->id, 'quantity' => 3]);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'group_id' => $men->id, 'quantity' => 5]);

    $issued = app(IssuingService::class)->issueStock(User::factory()->create(), $type, $size, 2, group: $women);

    expect($issued->group_id)->toBe($women->id);
    expect(Stock::where('group_id', $women->id)->value('quantity'))->toBe(1);
    expect(Stock::where('group_id', $men->id)->value('quantity'))->toBe(5);

    app(IssuingService::class)->return($issued);

    expect(Stock::where('group_id', $women->id)->value('quantity'))->toBe(3);
    expect(Stock::where('group_id', $men->id)->value('quantity'))->toBe(5);
});

test('groups and the chainsaw flag survive an export/import round trip', function () {
    $category = ItemCategory::create(['name' => 'Schutzkleidung', 'sort_order' => 1]);
    $type = ItemType::create(['name' => 'Schnittschutzhose', 'category_id' => $category->id, 'is_tracked' => true, 'is_chainsaw_protection' => true]);
    $viking = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Viking']);
    Item::create(['item_type_id' => $type->id, 'group_id' => $viking->id, 'code' => 'WEHRSSH0001', 'status' => 'available']);

    $service = app(DataTransferService::class);
    $exported = $service->export(['catalog', 'inventory']);

    Item::withTrashed()->forceDelete();
    ItemGroup::query()->delete();
    ItemType::query()->delete();

    $service->import($exported);

    $type = ItemType::where('name', 'Schnittschutzhose')->first();
    expect($type->is_chainsaw_protection)->toBeTrue();
    expect($type->groups()->pluck('name')->all())->toBe(['Viking']);
    expect(Item::where('code', 'WEHRSSH0001')->first()->group->name)->toBe('Viking');
});

test('there is only one stock row per type, size and group, even without size or group', function () {
    $type = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);
    Stock::create(['item_type_id' => $type->id, 'quantity' => 4]);

    expect(fn () => Stock::create(['item_type_id' => $type->id, 'quantity' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

// ─── Größen je Gruppe ────────────────────────────────────────────────────────

function sizesManager(ItemType $type)
{
    return Livewire::test(SizesRelationManager::class, [
        'ownerRecord' => $type, 'pageClass' => EditItemType::class,
    ]);
}

test('sizes can be assigned to a group, the same size name may exist once per group', function () {
    $type = ItemType::create(['name' => 'Diensthose', 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    $men = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Herren']);

    sizesManager($type)->callAction(TestAction::make('create')->table(), ['name' => '40', 'group_id' => $women->id])->assertHasNoFormErrors();
    sizesManager($type)->callAction(TestAction::make('create')->table(), ['name' => '40', 'group_id' => $men->id])->assertHasNoFormErrors();
    sizesManager($type)->callAction(TestAction::make('create')->table(), ['name' => 'Einheitsgröße'])->assertHasNoFormErrors();

    sizesManager($type)
        ->callAction(TestAction::make('create')->table(), ['name' => '40', 'group_id' => $women->id])
        ->assertHasFormErrors(['name' => 'unique']);

    expect(Size::where('item_type_id', $type->id)->count())->toBe(3);
    expect(Size::where('group_id', $women->id)->value('name'))->toBe('40');
});

test('the size select only offers sizes of the chosen group plus sizes for all groups', function () {
    $type = ItemType::create(['name' => 'Diensthose', 'is_tracked' => true, 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    $men = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Herren']);
    Size::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'name' => '38']);
    Size::create(['item_type_id' => $type->id, 'group_id' => $men->id, 'name' => '52']);
    Size::create(['item_type_id' => $type->id, 'name' => 'Einheitsgröße']);

    Livewire::test(CreateItem::class)
        ->fillForm(['item_type_id' => $type->id, 'group_id' => $women->id])
        ->assertFormFieldExists('size_id', fn ($field) => array_values($field->getOptions()) === ['38', 'Einheitsgröße'])
        ->fillForm(['group_id' => $men->id])
        ->assertFormFieldExists('size_id', fn ($field) => array_values($field->getOptions()) === ['52', 'Einheitsgröße']);
});

test('a group that still has sizes cannot be deleted', function () {
    $type = ItemType::create(['name' => 'Diensthose', 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    Size::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'name' => '38']);

    groupsManager($type)->callAction(TestAction::make('delete')->table($women));

    expect($women->fresh())->not->toBeNull();
});

test('group-specific sizes survive an export/import round trip', function () {
    $type = ItemType::create(['name' => 'Diensthose', 'is_tracked' => true, 'has_size' => true]);
    $women = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Damen']);
    $men = ItemGroup::create(['item_type_id' => $type->id, 'name' => 'Herren']);
    $w40 = Size::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'name' => '40']);
    Size::create(['item_type_id' => $type->id, 'group_id' => $men->id, 'name' => '40']);
    Item::create(['item_type_id' => $type->id, 'group_id' => $women->id, 'size_id' => $w40->id, 'code' => 'WEHRDH0001', 'status' => 'available']);

    $service = app(DataTransferService::class);
    $exported = $service->export(['catalog', 'inventory']);

    Item::withTrashed()->forceDelete();
    Size::query()->delete();
    ItemGroup::query()->delete();
    ItemType::query()->delete();

    $service->import($exported);

    $item = Item::where('code', 'WEHRDH0001')->first();
    expect(Size::count())->toBe(2);
    expect($item->group->name)->toBe('Damen');
    expect($item->size->name)->toBe('40');
    expect($item->size->group->name)->toBe('Damen');
});
