<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;

beforeEach(function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Cache::forget('module_auto_assign_kleiderkammer');
});

function memberUser(?string $role = null): User
{
    $user = User::factory()->create(['email' => 'hanna.hydrant@example.com']);
    $user->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => $role]);

    return $user;
}

test('member sees their own issued equipment', function () {
    $user = memberUser();
    $other = User::factory()->create(['name' => 'Bernd Blaulicht']);

    $jacket = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $helmet = ItemType::create(['name' => 'Helm', 'is_tracked' => true]);
    $mine = Item::create(['item_type_id' => $jacket->id, 'code' => 'WEHR-0001', 'status' => ItemStatus::Issued]);
    $theirs = Item::create(['item_type_id' => $helmet->id, 'code' => 'WEHR-0002', 'status' => ItemStatus::Issued]);

    IssuedItem::create(['user_id' => $user->id, 'item_id' => $mine->id, 'issued_at' => now()]);
    IssuedItem::create(['user_id' => $other->id, 'item_id' => $theirs->id, 'issued_at' => now()]);

    $this->actingAs($user)
        ->get('/kleiderkammer')
        ->assertOk()
        ->assertSee('Einsatzjacke')
        ->assertSee('WEHR-0001')
        ->assertDontSee('WEHR-0002')
        ->assertDontSee('Kleiderkammer verwalten');
});

test('returned equipment is not listed', function () {
    $user = memberUser();
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHR-0003', 'status' => ItemStatus::Available]);
    IssuedItem::create(['user_id' => $user->id, 'item_id' => $item->id, 'issued_at' => now()->subMonth(), 'returned_at' => now()]);

    $this->actingAs($user)
        ->get('/kleiderkammer')
        ->assertOk()
        ->assertDontSee('WEHR-0003')
        ->assertSee('keine Ausrüstung ausgegeben');
});

// The way into the Kleiderkammer area is the gear ("Verwaltung") of the Modulleiste, no extra button
test('kleiderwart and admin reach the management area through the Modulleiste', function (string $who) {
    $user = $who === 'admin' ? User::factory()->admin()->create() : memberUser('kleiderwart');

    $this->actingAs($user)
        ->get('/kleiderkammer')
        ->assertOk()
        ->assertSee('href="'.route('filament.admin.pages.kleiderkammer').'" title="Verwaltung"', false)
        ->assertDontSee('Kleiderkammer verwalten');
})->with(['kleiderwart', 'admin']);

test('plain member cannot open the management panel', function () {
    $this->actingAs(memberUser())
        ->get('/admin/kleiderkammer')
        ->assertForbidden();
});

test('user without module access cannot open the equipment page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/kleiderkammer')
        ->assertForbidden();
});

test('home dashboard links the kleiderkammer card to the equipment page', function () {
    $this->actingAs(memberUser())
        ->get('/')
        ->assertOk()
        ->assertSee(route('kleiderkammer.index'), false);
});
