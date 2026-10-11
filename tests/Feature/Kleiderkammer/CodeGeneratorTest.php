<?php

use App\Models\Setting;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Services\CodeGenerator;

function makeJacketType(): ItemType
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

beforeEach(function () {
    // Reset code prefix to default
    Setting::forget('org_code_prefix');
});

test('generates a code with the default WEHR prefix', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    $code = $generator->generate($type);

    expect($code)->toStartWith('WEHREJA');
    expect(strlen($code))->toBe(11); // WEHR(4) + EJA(3) + 0001(4)
    expect($code)->toBe('WEHREJA0001');
});

test('uses the configured org_code_prefix', function () {
    Setting::set('org_code_prefix', 'KFW1');
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    $code = $generator->generate($type);

    expect($code)->toStartWith('KFW1EJA');
    expect($code)->toBe('KFW1EJA0001');
});

test('increments the sequence from the highest existing code', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    // Pre-insert items at 0001 and 0003 to test gap-filling (next = 4, not 2)
    Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0003', 'status' => 'available']);

    $code = $generator->generate($type);

    expect($code)->toBe('WEHREJA0004');
});

test('sequence restarts at 0001 when no items exist for the prefix', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    expect($generator->generate($type))->toBe('WEHREJA0001');
});

test('pads the number to four digits', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    for ($i = 1; $i <= 9; $i++) {
        Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA'.str_pad($i, 4, '0', STR_PAD_LEFT), 'status' => 'available']);
    }

    $code = $generator->generate($type);

    expect($code)->toBe('WEHREJA0010');
});

test('includes soft-deleted items in the sequence to prevent code reuse', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $item->delete(); // soft delete

    // Next code must not reuse 0001
    expect($generator->generate($type))->toBe('WEHREJA0002');
});

test('matches returns true when code starts with the combined prefix', function () {
    $generator = app(CodeGenerator::class);
    $type = makeJacketType();

    expect($generator->matches('WEHREJA0001', $type))->toBeTrue();
    expect($generator->matches('WEHREHO0001', $type))->toBeFalse();
});

test('two different item types get independent sequences', function () {
    $generator = app(CodeGenerator::class);

    $jacket = ItemType::create(['name' => 'Einsatzjacke', 'code_prefix' => 'EJA', 'is_tracked' => true, 'has_size' => false, 'is_washable' => false, 'sort_order' => 1]);
    $trousers = ItemType::create(['name' => 'Einsatzhose', 'code_prefix' => 'EHO', 'is_tracked' => true, 'has_size' => false, 'is_washable' => false, 'sort_order' => 2]);

    Item::create(['item_type_id' => $jacket->id, 'code' => 'WEHREJA0001', 'status' => 'available']);

    expect($generator->generate($jacket))->toBe('WEHREJA0002');
    expect($generator->generate($trousers))->toBe('WEHREHO0001');
});
