<?php

use App\Models\User;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeTrackedType(): ItemType
{
    return ItemType::create([
        'name' => 'Einsatzjacke',
        'code_prefix' => 'EJA',
        'is_tracked' => true,
        'has_size' => true,
        'is_washable' => false,
        'sort_order' => 1,
    ]);
}

function makeStockType(): ItemType
{
    return ItemType::create([
        'name' => 'Handschuhe',
        'code_prefix' => 'HAN',
        'is_tracked' => false,
        'has_size' => true,
        'is_washable' => false,
        'sort_order' => 2,
    ]);
}

function makeMember(string $name = 'Hanna Hydrant'): User
{
    return User::factory()->create(['name' => $name]);
}

function makeAvailableItem(ItemType $type, ?Size $size = null): Item
{
    return Item::create([
        'item_type_id' => $type->id,
        'size_id' => $size?->id,
        'code' => 'WEHREJA'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'status' => ItemStatus::Available,
    ]);
}

// ─── issueTracked ────────────────────────────────────────────────────────────

test('issueTracked creates an issued-item record and marks the item as issued', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);

    $issued = $service->issueTracked($member, $item, 'Erste Ausgabe');

    expect($issued)->toBeInstanceOf(IssuedItem::class);
    expect($issued->user_id)->toBe($member->id);
    expect($issued->item_id)->toBe($item->id);
    expect($issued->quantity)->toBe(1);
    expect($issued->notes)->toBe('Erste Ausgabe');
    expect($issued->isOpen())->toBeTrue();
    expect($item->fresh()->status)->toBe(ItemStatus::Issued);
});

test('issueTracked throws when the item is already issued', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);

    $service->issueTracked($member, $item);

    expect(fn () => $service->issueTracked($member, $item))
        ->toThrow(DomainException::class);
});

test('issueTracked throws when the item is retired', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);
    $item->update(['status' => ItemStatus::Retired]);

    expect(fn () => $service->issueTracked($member, $item))
        ->toThrow(DomainException::class);
});

// ─── issueStock ──────────────────────────────────────────────────────────────

test('issueStock creates a record and decrements stock quantity', function () {
    $service = app(IssuingService::class);
    $type = makeStockType();
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 0]);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'quantity' => 10]);
    $member = makeMember();

    $issued = $service->issueStock($member, $type, $size, 3, 'Drillaus');

    expect($issued->item_type_id)->toBe($type->id);
    expect($issued->size_id)->toBe($size->id);
    expect($issued->quantity)->toBe(3);
    expect(Stock::where('item_type_id', $type->id)->where('size_id', $size->id)->value('quantity'))->toBe(7);
});

test('issueStock throws when stock is insufficient', function () {
    $service = app(IssuingService::class);
    $type = makeStockType();
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'S', 'sort_order' => 0]);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'quantity' => 2]);
    $member = makeMember();

    expect(fn () => $service->issueStock($member, $type, $size, 5))
        ->toThrow(DomainException::class);
});

test('issueStock throws for zero or negative quantity', function () {
    $service = app(IssuingService::class);
    $type = makeStockType();
    $member = makeMember();

    expect(fn () => $service->issueStock($member, $type, null, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->issueStock($member, $type, null, -1))
        ->toThrow(InvalidArgumentException::class);
});

test('issueStock with no stock row throws for any quantity', function () {
    $service = app(IssuingService::class);
    $type = makeStockType();
    $member = makeMember();

    expect(fn () => $service->issueStock($member, $type, null, 1))
        ->toThrow(DomainException::class);
});

// ─── return ───────────────────────────────────────────────────────────────────

test('return sets returned_at and marks tracked item available again', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);
    $issued = $service->issueTracked($member, $item);

    $service->return($issued);

    expect($issued->fresh()->isReturned())->toBeTrue();
    expect($item->fresh()->status)->toBe(ItemStatus::Available);
});

test('return restores stock quantity for stock items', function () {
    $service = app(IssuingService::class);
    $type = makeStockType();
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'L', 'sort_order' => 0]);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'quantity' => 5]);
    $member = makeMember();

    $issued = $service->issueStock($member, $type, $size, 2);
    $service->return($issued);

    expect(Stock::where('item_type_id', $type->id)->where('size_id', $size->id)->value('quantity'))->toBe(5);
});

test('return throws when record is already returned', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);
    $issued = $service->issueTracked($member, $item);

    $service->return($issued);

    expect(fn () => $service->return($issued->fresh()))
        ->toThrow(DomainException::class);
});

// ─── takeBack ────────────────────────────────────────────────────────────────

test('takeBack returns all open items of a member', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item1 = makeAvailableItem($type);
    $item2 = makeAvailableItem($type);

    $service->issueTracked($member, $item1);
    $service->issueTracked($member, $item2);

    expect($member->kleiderkammerItems()->exists())->toBeTrue();

    $service->takeBack($member);

    expect($member->kleiderkammerItems()->count())->toBe(0);
    expect($item1->fresh()->status)->toBe(ItemStatus::Available);
    expect($item2->fresh()->status)->toBe(ItemStatus::Available);
});

test('takeBack on a member with no items does nothing', function () {
    $service = app(IssuingService::class);
    $member = makeMember();

    $service->takeBack($member); // must not throw

    expect($member->kleiderkammerItems()->exists())->toBeFalse();
});

// ─── retire ───────────────────────────────────────────────────────────────────

test('retire marks an available item as retired', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $item = makeAvailableItem($type);

    $service->retire($item, 'Verschlissen');

    expect($item->fresh()->status)->toBe(ItemStatus::Retired);
    expect($item->fresh()->notes)->toBe('Verschlissen');
});

test('retire throws when item is currently issued', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $member = makeMember();
    $item = makeAvailableItem($type);

    app(IssuingService::class)->issueTracked($member, $item);

    expect(fn () => $service->retire($item->fresh()))
        ->toThrow(DomainException::class);
});

test('retire already-retired item does not throw', function () {
    $service = app(IssuingService::class);
    $type = makeTrackedType();
    $item = makeAvailableItem($type);
    $item->update(['status' => ItemStatus::Retired]);

    $service->retire($item); // should succeed silently

    expect($item->fresh()->status)->toBe(ItemStatus::Retired);
});
