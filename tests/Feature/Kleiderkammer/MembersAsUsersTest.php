<?php

use App\Enums\MemberStatus;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages\ListMembers;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Services\IssuingService;

// ─── status ↔ login ──────────────────────────────────────────────────────────

test('status decides whether a user may log in', function () {
    $user = User::factory()->create();
    expect($user->status)->toBe(MemberStatus::Active);
    expect($user->is_active)->toBeTrue();

    $user->update(['status' => MemberStatus::Left]);
    expect($user->is_active)->toBeFalse();

    $user->update(['status' => MemberStatus::Active]);
    expect($user->is_active)->toBeTrue();
});

test('deactivating via is_active sets the status to inactive but keeps left', function () {
    $user = User::factory()->create();
    $user->update(['is_active' => false]);
    expect($user->status)->toBe(MemberStatus::Inactive);

    $left = User::factory()->create(['status' => MemberStatus::Left]);
    $left->update(['is_active' => false]);
    expect($left->status)->toBe(MemberStatus::Left);
});

test('admin maintains member data in the user form', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['phone' => null]);
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'status' => 'left',
            'groups' => [$youth->id],
            'rank' => 'Oberlöschmeisterin',
            'gender' => 'female',
            'notes' => 'Helm Größe M bestellt',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect($user->status)->toBe(MemberStatus::Left);
    expect($user->is_active)->toBeFalse();
    expect($user->groups->pluck('name')->all())->toBe(['Jugendfeuerwehr']);
    expect($user->rank)->toBe('Oberlöschmeisterin');
    expect($user->notes)->toBe('Helm Größe M bestellt');
});

// ─── Kleiderkammer ───────────────────────────────────────────────────────────

test('items cannot be issued to members who are not active', function () {
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'available']);
    $left = User::factory()->create(['name' => 'Bernd Blaulicht', 'status' => MemberStatus::Left]);

    expect(fn () => app(IssuingService::class)->issueTracked($left, $item))->toThrow(DomainException::class);
    expect($item->fresh()->status->value)->toBe('available');
});

test('kleiderwart sees members with their equipment count but cannot create or delete them', function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $kleiderwart = User::factory()->create();
    $kleiderwart->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'rank' => 'Gruppenführerin']);
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    IssuedItem::create(['user_id' => $hanna->id, 'item_id' => $item->id, 'issued_at' => now()]);

    Livewire::actingAs($kleiderwart)
        ->test(ListMembers::class)
        ->assertCanSeeTableRecords([$hanna])
        ->assertTableColumnStateSet('kleiderkammer_items_count', 1, $hanna)
        ->assertTableActionVisible('takeBack', $hanna)
        ->assertTableActionDoesNotExist('delete');

    $this->actingAs($kleiderwart)->get('/admin/kleiderkammer/kameraden/neu')->assertNotFound();
});

// ─── migration from kk_members ───────────────────────────────────────────────

test('migration turns kk_members into users and keeps their issuances', function () {
    $migration = require base_path('modules/Kleiderkammer/database/migrations/2026_10_10_000001_move_kk_members_to_users.php');
    $migration->down();

    // The migration predates the move of "Abteilung" to the groups; it still fills users.department
    Schema::table('users', fn ($table) => $table->string('department', 30)->nullable());

    $existing = User::factory()->admin()->create(['email' => 'hanna.hydrant@example.com']);
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $jacket = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    $helmet = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0002', 'status' => 'issued']);

    $member = fn (array $attrs) => DB::table('kk_members')->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);
    $hanna = $member(['name' => 'Hanna Hydrant', 'email' => 'Hanna.Hydrant@example.com', 'role' => 'Gruppenführerin', 'department' => 'active_service', 'status' => 'inactive']);
    $bernd = $member(['name' => 'Bernd Blaulicht', 'email' => 'bernd.blaulicht@example.com', 'status' => 'active', 'deleted_at' => now()]);
    $klara = $member(['name' => 'Klara Lösch', 'email' => null, 'status' => 'active']);

    $issue = fn (int $memberId, int $itemId) => DB::table('kk_issued_items')->insert([
        'member_id' => $memberId, 'item_id' => $itemId, 'quantity' => 1, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $issue($hanna, $jacket->id);
    $issue($klara, $helmet->id);

    ob_start();
    $migration->up();
    $output = ob_get_clean();

    $existing->refresh();
    expect($existing->rank)->toBe('Gruppenführerin');
    expect($existing->is_active)->toBeTrue(); // existing logins are not locked out
    expect($existing->kleiderkammerItems()->value('item_id'))->toBe($jacket->id);

    $berndUser = User::where('email', 'bernd.blaulicht@example.com')->first();
    expect($berndUser->status)->toBe(MemberStatus::Left);
    expect($berndUser->is_active)->toBeFalse();

    expect(User::where('name', 'Klara Lösch')->exists())->toBeFalse();
    expect($helmet->fresh()->status->value)->toBe('available');
    expect($output)->toContain('1 Ausgabe(n)');
    expect(DB::getSchemaBuilder()->hasTable('kk_members'))->toBeFalse();

    Schema::table('users', fn ($table) => $table->dropColumn('department'));
});

test('a user with equipment cannot be deleted and the admin is told why', function () {
    $admin = User::factory()->admin()->create();
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    IssuedItem::create(['user_id' => $hanna->id, 'item_id' => $item->id, 'issued_at' => now()]);

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $hanna->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Nicht gelöscht: Hanna Hydrant');

    expect($hanna->fresh())->not->toBeNull();
});

test('a user without attached data can still be deleted', function () {
    $admin = User::factory()->admin()->create();
    $bernd = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $bernd->getRouteKey()])
        ->callAction('delete');

    expect($bernd->fresh())->toBeNull();
});

test('bulk delete keeps users with attached data', function () {
    $admin = User::factory()->admin()->create();
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $type = ItemType::create(['name' => 'Einsatzjacke', 'is_tracked' => true]);
    $item = Item::create(['item_type_id' => $type->id, 'code' => 'WEHREJA0001', 'status' => 'issued']);
    IssuedItem::create(['user_id' => $hanna->id, 'item_id' => $item->id, 'issued_at' => now()]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->callTableBulkAction('delete', [$hanna, $bernd])
        ->assertNotified('Nicht gelöscht: Hanna Hydrant');

    expect($hanna->fresh())->not->toBeNull();
    expect($bernd->fresh())->toBeNull();
});
