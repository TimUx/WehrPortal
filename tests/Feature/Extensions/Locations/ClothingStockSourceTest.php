<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;

beforeEach(function () {
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->type = ItemType::create(['name' => 'Handschuhe', 'is_tracked' => false]);
    $this->size = Size::create(['item_type_id' => $this->type->id, 'name' => 'M']);
    $this->group = ItemGroup::create(['item_type_id' => $this->type->id, 'name' => 'Einsatz']);
    $this->stock = Stock::create([
        'item_type_id' => $this->type->id, 'size_id' => $this->size->id,
        'group_id' => $this->group->id, 'quantity' => 12,
    ]);
    $this->service = app(IssuingService::class);
    $this->issue = fn () => $this->service->issueStock($this->member, $this->type, $this->size, 3, 'Ausgabe', $this->group);
    $this->migration = require base_path('extensions/Locations/database/migrations/2026_12_26_000000_record_clothing_issuance_stock_source.php');
});

it('records the locked exact stock without making provenance client assignable or journaling assignments', function (bool $enabled) {
    config(['locations.enabled' => $enabled]);
    $issued = ($this->issue)();
    expect($issued->getAttributes()['ext_source_stock_id'])->toBe($this->stock->id)
        ->and($issued->getFillable())->not->toContain('ext_source_stock_id')
        ->and($this->stock->fresh()->quantity)->toBe(9);
    $this->service->return($issued);
    expect($this->stock->fresh()->quantity)->toBe(12)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with([true, false]);

it('keeps tracked issuance and return unchanged and unsourced', function () {
    $item = Item::create(['item_type_id' => $this->type->id, 'code' => 'SOURCE-TRACKED', 'status' => ItemStatus::Available]);
    $issued = $this->service->issueTracked($this->member, $item);
    expect($issued->fresh()->getAttributes()['ext_source_stock_id'])->toBeNull();
    $this->service->return($issued);
    expect($item->fresh()->status)->toBe(ItemStatus::Available)
        ->and($this->stock->fresh()->quantity)->toBe(12);
});

it('returns legacy null source records through the unchanged catalog triplet fallback', function () {
    $issued = IssuedItem::create([
        'user_id' => $this->member->id, 'item_type_id' => $this->type->id,
        'size_id' => null, 'group_id' => null, 'quantity' => 2, 'issued_at' => now(),
    ]);
    expect($issued->fresh()->getAttributes()['ext_source_stock_id'])->toBeNull();
    $this->service->return($issued);
    expect(Stock::where('item_type_id', $this->type->id)->whereNull('size_id')->whereNull('group_id')->value('quantity'))->toBe(2)
        ->and($this->stock->fresh()->quantity)->toBe(12);
});

it('returns to the exact original row after source catalogs change even when Locations is disabled', function () {
    $issued = ($this->issue)();
    $otherType = ItemType::create(['name' => 'Gürtel', 'is_tracked' => false]);
    $this->stock->update(['item_type_id' => $otherType->id, 'size_id' => null, 'group_id' => null]);
    $replacement = Stock::create([
        'item_type_id' => $this->type->id, 'size_id' => $this->size->id,
        'group_id' => $this->group->id, 'quantity' => 5,
    ]);
    config(['locations.enabled' => false]);
    $this->service->return($issued);
    expect($this->stock->fresh()->quantity)->toBe(12)
        ->and($replacement->fresh()->quantity)->toBe(5)
        ->and($issued->fresh()->item_type_id)->toBe($this->type->id);
});

it('honors source before nullable or corrected issuance catalog snapshots', function () {
    $issued = ($this->issue)();
    $issued->update(['item_type_id' => null, 'size_id' => null, 'group_id' => null, 'notes' => 'Historischer Stand']);
    $this->service->return($issued);
    expect($this->stock->fresh()->quantity)->toBe(12)
        ->and($issued->fresh()->returned_at)->not->toBeNull();
});

it('permits catalog null-on-delete snapshots without losing the exact return source', function () {
    $issued = ($this->issue)();
    $this->size->delete();
    expect($issued->fresh()->size_id)->toBeNull()
        ->and($issued->fresh()->getAttributes()['ext_source_stock_id'])->toBe($this->stock->id);
    $this->service->return($issued);
    expect($this->stock->fresh()->quantity)->toBe(12);
});

it('conserves quantities through multiple issues takeBack and double return rejection', function () {
    $first = ($this->issue)();
    $second = ($this->issue)();
    expect($this->stock->fresh()->quantity + $first->quantity + $second->quantity)->toBe(12);
    $first->update(['size_id' => null, 'group_id' => null]);
    $this->service->takeBack($this->member);
    expect($this->stock->fresh()->quantity)->toBe(12)
        ->and($first->fresh()->isReturned())->toBeTrue()
        ->and($second->fresh()->isReturned())->toBeTrue();
    expect(fn () => $this->service->return($first))->toThrow(DomainException::class);
    expect($this->stock->fresh()->quantity)->toBe(12);
});

it('fails atomically without catalog fallback if a custom installation has lost its source row', function () {
    $issued = ($this->issue)();
    DB::statement('ALTER TABLE kk_issued_items DROP CONSTRAINT kk_issued_items_ext_source_stock_id_foreign');
    $this->stock->delete();
    $replacement = Stock::create([
        'item_type_id' => $this->type->id, 'size_id' => $this->size->id,
        'group_id' => $this->group->id, 'quantity' => 5,
    ]);
    expect(fn () => $this->service->return($issued))->toThrow(ModelNotFoundException::class);
    expect($issued->fresh()->returned_at)->toBeNull()
        ->and($replacement->fresh()->quantity)->toBe(5);
});

it('rejects corrupted tracked plus source returns atomically even if the custom schema lost its check', function () {
    $issued = ($this->issue)();
    $item = Item::create(['item_type_id' => $this->type->id, 'code' => 'SOURCE-INVALID', 'status' => ItemStatus::Issued]);
    DB::statement('ALTER TABLE kk_issued_items DROP CONSTRAINT kk_issued_items_stock_source_bulk');
    $issued->update(['item_id' => $item->id]);
    expect(fn () => $this->service->return($issued))->toThrow(DomainException::class);
    expect($issued->fresh()->returned_at)->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(9)
        ->and($item->fresh()->status)->toBe(ItemStatus::Issued);
});

it('rejects every source rewrite including historical null to source', function (string $change) {
    $issued = $change === 'attach' ? IssuedItem::create([
        'user_id' => $this->member->id, 'item_type_id' => $this->type->id, 'issued_at' => now(),
    ]) : ($this->issue)();
    $other = Stock::create(['item_type_id' => $this->type->id, 'quantity' => 1]);
    $target = match ($change) {
        'clear' => null, 'replace' => $other->id, default => $this->stock->id,
    };
    expect(fn () => DB::transaction(fn () => DB::table('kk_issued_items')->where('id', $issued->id)->update(['ext_source_stock_id' => $target])))
        ->toThrow(QueryException::class, 'immutable');
})->with(['attach', 'clear', 'replace']);

it('rejects orphan mismatched tracked and nonpositive new source rows in the database', function (string $invalid) {
    $row = [
        'user_id' => $this->member->id, 'item_type_id' => $this->type->id,
        'size_id' => $this->size->id, 'group_id' => $this->group->id,
        'quantity' => 1, 'issued_at' => now(), 'ext_source_stock_id' => $this->stock->id,
    ];
    $row = [...$row, ...match ($invalid) {
        'orphan' => ['ext_source_stock_id' => 99999999],
        'type' => ['item_type_id' => null],
        'size' => ['size_id' => null],
        'group' => ['group_id' => null],
        'zero' => ['quantity' => 0],
        'negative' => ['quantity' => -1],
        default => ['item_id' => Item::create(['item_type_id' => $this->type->id, 'code' => 'SOURCE-CHECK'])->id],
    }];
    expect(fn () => DB::transaction(fn () => DB::table('kk_issued_items')->insert($row)))->toThrow(QueryException::class);
})->with(['orphan', 'type', 'size', 'group', 'zero', 'negative', 'tracked']);

it('continues to enforce positive bulk-only sources on updates without freezing other snapshots', function (string $invalid) {
    $issued = ($this->issue)();
    $update = $invalid === 'tracked'
        ? ['item_id' => Item::create(['item_type_id' => $this->type->id, 'code' => 'SOURCE-UPDATE'])->id]
        : ['quantity' => 0];
    expect(fn () => DB::transaction(fn () => DB::table('kk_issued_items')->where('id', $issued->id)->update($update)))
        ->toThrow(QueryException::class);
    $issued->update(['quantity' => 2, 'notes' => 'Korrektur', 'size_id' => null]);
    expect($issued->fresh()->quantity)->toBe(2);
})->with(['tracked', 'quantity']);

it('restricts source parent deletion and key updates even for returned softdeleted issues', function (string $operation) {
    $issued = ($this->issue)();
    $this->service->return($issued);
    $issued->delete();
    expect(fn () => DB::transaction(fn () => $operation === 'delete'
        ? DB::table('kk_stock')->where('id', $this->stock->id)->delete()
        : DB::table('kk_stock')->where('id', $this->stock->id)->update(['id' => $this->stock->id + 100000])))
        ->toThrow(QueryException::class);
    expect(DB::table('kk_issued_items')->where('id', $issued->id)->value('ext_source_stock_id'))->toBe($this->stock->id);
})->with(['delete', 'update']);

it('refuses rollback for every retained source including returned and deleted history', function (string $state) {
    $issued = ($this->issue)();
    if ($state !== 'open') {
        $this->service->return($issued);
    }
    if ($state === 'deleted') {
        $issued->delete();
    }
    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'historical sources');
    expect(Schema::hasColumn('kk_issued_items', 'ext_source_stock_id'))->toBeTrue()
        ->and(DB::table('kk_issued_items')->where('id', $issued->id)->value('ext_source_stock_id'))->toBe($this->stock->id);
})->with(['open', 'returned', 'deleted']);

it('does not replace a preexisting custom source column foreign key or data on upgrade', function () {
    $this->migration->down();
    Schema::table('kk_issued_items', function (Blueprint $table) {
        $table->foreignId('ext_source_stock_id')->nullable();
        $table->foreign('ext_source_stock_id', 'custom_stock_source')->references('id')->on('kk_stock')->nullOnDelete();
    });
    $issued = ($this->issue)();
    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'already exists');
    expect($issued->fresh()->getAttributes()['ext_source_stock_id'])->toBe($this->stock->id)
        ->and(collect(Schema::getForeignKeys('kk_issued_items'))->pluck('name')->all())->toContain('custom_stock_source');
});

it('guards optional module tables without inventing provenance', function (string $table) {
    $this->migration->down();
    Schema::rename($table, $table.'_absent');
    $this->migration->up();
    Schema::rename($table.'_absent', $table);
    expect(Schema::hasColumn('kk_issued_items', 'ext_source_stock_id'))->toBeFalse();
    $this->migration->up();
    expect(Schema::hasColumn('kk_issued_items', 'ext_source_stock_id'))->toBeTrue();
})->with(['kk_issued_items', 'kk_stock']);

it('allows distinct warehouse owners while retaining null-safe uniqueness within each owner', function () {
    $location = DB::table('x_locations')->value('id');
    DB::table('kk_stock')->insert([
        'item_type_id' => $this->type->id, 'size_id' => $this->size->id,
        'group_id' => $this->group->id, 'quantity' => 1, 'ext_location_id' => $location,
    ]);
    expect(fn () => DB::transaction(fn () => DB::table('kk_stock')->insert([
        'item_type_id' => $this->type->id, 'size_id' => $this->size->id,
        'group_id' => $this->group->id, 'quantity' => 1, 'ext_location_id' => $location,
    ])))->toThrow(QueryException::class);
    Stock::create(['item_type_id' => $this->type->id, 'quantity' => 1]);
    expect(fn () => DB::transaction(fn () => Stock::create(['item_type_id' => $this->type->id, 'quantity' => 1])))
        ->toThrow(QueryException::class);
});
