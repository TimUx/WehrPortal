<?php

use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Services\CodeResolver;

function seedType(): ItemType
{
    return ItemType::create([
        'name' => 'Einsatzjacke',
        'code_prefix' => 'EJA',
        'is_tracked' => true,
        'has_size' => false,
        'is_washable' => false,
        'sort_order' => 1,
    ]);
}

test('resolve finds a live item by its code', function () {
    $type = seedType();
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    $found = app(CodeResolver::class)->resolve('WEHREJA0001');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($item->id);
});

test('resolve returns null for an unknown code', function () {
    expect(app(CodeResolver::class)->resolve('NOTEXIST'))->toBeNull();
});

test('resolve finds soft-deleted items', function () {
    $type = seedType();
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $item->delete();

    $found = app(CodeResolver::class)->resolve('WEHREJA0001');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($item->id);
    expect($found->trashed())->toBeTrue();
});

test('resolveActive returns null for soft-deleted items', function () {
    $type = seedType();
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $item->delete();

    expect(app(CodeResolver::class)->resolveActive('WEHREJA0001'))->toBeNull();
});

test('resolveActive finds a live item', function () {
    $type = seedType();
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    $found = app(CodeResolver::class)->resolveActive('WEHREJA0001');

    expect($found?->id)->toBe($item->id);
});

test('resolve is case-sensitive', function () {
    $type = seedType();
    Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    expect(app(CodeResolver::class)->resolve('wehreja0001'))->toBeNull();
});
