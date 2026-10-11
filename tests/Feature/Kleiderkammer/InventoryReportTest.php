<?php

use App\Models\User;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\InventoryReport;

function makeTracked(string $name = 'Jacket'): ItemType
{
    static $i = 0;

    return ItemType::create([
        'name' => $name.(++$i),
        'code_prefix' => 'X'.str_pad($i, 2, '0', STR_PAD_LEFT),
        'is_tracked' => true,
        'has_size' => false,
        'is_washable' => false,
        'sort_order' => $i,
    ]);
}

function makeUntracked(string $name = 'Gloves'): ItemType
{
    static $j = 0;

    return ItemType::create([
        'name' => $name.(++$j),
        'code_prefix' => 'Y'.str_pad($j, 2, '0', STR_PAD_LEFT),
        'is_tracked' => false,
        'has_size' => false,
        'is_washable' => false,
        'sort_order' => $j,
    ]);
}

function makeItem(ItemType $type, ItemStatus $status = ItemStatus::Available): Item
{
    static $k = 0;

    return Item::create([
        'item_type_id' => $type->id,
        'code' => 'WEHRTEST'.str_pad(++$k, 4, '0', STR_PAD_LEFT),
        'status' => $status,
    ]);
}

// ─── summary ─────────────────────────────────────────────────────────────────

test('summary returns zeros on empty database', function () {
    $report = app(InventoryReport::class);
    $summary = $report->summary();

    expect($summary['tracked_total'])->toBe(0);
    expect($summary['available'])->toBe(0);
    expect($summary['issued'])->toBe(0);
    expect($summary['retired'])->toBe(0);
    expect($summary['open_loans'])->toBe(0);
});

test('summary counts tracked items by status', function () {
    $type = makeTracked();
    makeItem($type, ItemStatus::Available);
    makeItem($type, ItemStatus::Available);
    makeItem($type, ItemStatus::Issued);
    makeItem($type, ItemStatus::Retired);

    $summary = app(InventoryReport::class)->summary();

    expect($summary['tracked_total'])->toBe(4);
    expect($summary['available'])->toBe(2);
    expect($summary['issued'])->toBe(1);
    expect($summary['retired'])->toBe(1);
});

test('summary counts open loans from issued_items table', function () {
    $type = makeTracked();
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $item1 = makeItem($type, ItemStatus::Issued);
    $item2 = makeItem($type, ItemStatus::Issued);

    IssuedItem::create(['user_id' => $member->id, 'item_id' => $item1->id, 'quantity' => 1, 'issued_at' => now()]);
    IssuedItem::create(['user_id' => $member->id, 'item_id' => $item2->id, 'quantity' => 1, 'issued_at' => now(), 'returned_at' => now()]);

    expect(app(InventoryReport::class)->summary()['open_loans'])->toBe(1);
});

// ─── trackedByType ────────────────────────────────────────────────────────────

test('trackedByType returns one row per tracked type', function () {
    $t1 = makeTracked('A');
    $t2 = makeTracked('B');
    makeItem($t1, ItemStatus::Available);
    makeItem($t1, ItemStatus::Issued);
    makeItem($t2, ItemStatus::Available);

    $rows = app(InventoryReport::class)->trackedByType();

    expect($rows)->toHaveCount(2);
    $row1 = $rows->firstWhere('item_type_id', $t1->id);
    expect($row1->total)->toBe(2);
    expect($row1->available)->toBe(1);
    expect($row1->issued)->toBe(1);
});

test('trackedByType does not include non-tracked types', function () {
    $tracked = makeTracked();
    $untracked = makeUntracked();
    makeItem($tracked);

    $rows = app(InventoryReport::class)->trackedByType();

    expect($rows->pluck('item_type_id'))->not->toContain($untracked->id);
});

// ─── stockByType ──────────────────────────────────────────────────────────────

test('stockByType returns stock rows for non-tracked types', function () {
    $type = makeUntracked();
    $size = Size::create(['item_type_id' => $type->id, 'name' => 'M', 'sort_order' => 0]);
    Stock::create(['item_type_id' => $type->id, 'size_id' => $size->id, 'quantity' => 7]);

    $rows = app(InventoryReport::class)->stockByType();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->quantity)->toBe(7);
    expect($rows->first()->size)->toBe('M');
});

// ─── openLoans ────────────────────────────────────────────────────────────────

test('openLoans returns only unreturned issued items', function () {
    $type = makeTracked();
    $member = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $item = makeItem($type, ItemStatus::Issued);
    $open = IssuedItem::create(['user_id' => $member->id, 'item_id' => $item->id, 'quantity' => 1, 'issued_at' => now()]);
    IssuedItem::create(['user_id' => $member->id, 'item_id' => $item->id, 'quantity' => 1, 'issued_at' => now()->subDay(), 'returned_at' => now()]);

    $loans = app(InventoryReport::class)->openLoans();

    expect($loans)->toHaveCount(1);
    expect($loans->first()->id)->toBe($open->id);
});
