<?php

use App\Models\Group;
use App\Models\User;
use Extensions\Locations\Filament\Resources\LocationResource\Pages\EditLocation;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers\GroupsRelationManager;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers\MembersRelationManager;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

function station(string $name): Location
{
    return Location::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
}

function memberAt(Location $location, string $role = 'member', bool $admin = false): User
{
    $user = User::factory()->create(['is_admin' => $admin]);
    $user->forceFill(['ext_location_id' => $location->id])->save();
    DB::table('x_location_user')->insert(['location_id' => $location->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

it('always has a default Standort', function () {
    if (Schema::hasTable('x_vv_associations')) {
        DB::table('x_vv_associations')->delete();
    }

    DB::table('x_locations')->delete();

    $default = Location::default();

    expect($default->is_default)->toBeTrue()
        ->and(Location::count())->toBe(1);

    $this->artisan('app:ensure-locations')->assertSuccessful();
    expect(Location::count())->toBe(1);
});

it('refuses to delete the default or the last Standort', function () {
    $default = Location::default();
    $other = station('Wache Nord');

    expect(fn () => $default->delete())->toThrow(ValidationException::class);

    $other->delete();
    expect(fn () => $default->delete())->toThrow(ValidationException::class);
});

it('gives new records the Standort of the creator, or the default without a user', function () {
    $default = Location::default();
    $north = station('Wache Nord');

    $anonymous = Vehicle::factory()->create();
    expect($anonymous->ext_location_id)->toBe($default->id);

    $this->actingAs(memberAt($north));
    $vehicle = Vehicle::factory()->create();
    expect($vehicle->ext_location_id)->toBe($north->id);
});

it('shows users only the records of their Standort, global admins everything', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $southVehicle = Vehicle::factory()->create(['ext_location_id' => $south->id]);
    $northVehicle = Vehicle::factory()->create(['ext_location_id' => $north->id]);
    $shared = Vehicle::factory()->create();
    $shared->forceFill(['ext_location_id' => null])->save();
    expect($shared->fresh()->ext_location_id)->toBeNull();

    $this->actingAs(memberAt($south));
    expect(Vehicle::pluck('id')->all())->toEqualCanonicalizing([$southVehicle->id, $shared->id])
        ->and(Vehicle::find($northVehicle->id))->toBeNull();

    auth()->logout();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    expect(Vehicle::count())->toBe(3);
});

it('does not filter without a signed-in user', function () {
    $south = station('Wache Süd');
    Vehicle::factory()->create(['ext_location_id' => $south->id]);
    Vehicle::factory()->create(['ext_location_id' => Location::default()->id]);

    expect(Vehicle::count())->toBe(2);
});

it('lets a Standort-Admin see the members of their Standort only', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $admin = memberAt($south, 'admin');
    $colleague = memberAt($south);
    $stranger = memberAt($north);

    $this->actingAs($admin);
    $ids = User::pluck('id')->all();

    expect($ids)->toContain($admin->id, $colleague->id)->not->toContain($stranger->id);
});

it('lets only Standort-Admins manage their own Standort', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $admin = memberAt($south, 'admin');
    $access = app(LocationAccess::class);

    expect($access->canManage($admin, $south))->toBeTrue()
        ->and($access->canManage($admin, $north))->toBeFalse()
        ->and($access->isLocationAdmin($admin))->toBeTrue()
        ->and($access->canManage(User::factory()->create(['is_admin' => true]), $north))->toBeTrue()
        ->and($access->isLocationAdmin(memberAt($north)))->toBeFalse();
});

it('lets Standort-Admins manage Abteilungen for their Standort', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $admin = memberAt($south, 'admin');
    $northAdmin = memberAt($north, 'admin');

    Livewire::actingAs($admin)
        ->test(GroupsRelationManager::class, ['ownerRecord' => $south, 'pageClass' => EditLocation::class])
        ->callTableAction('create', data: ['name' => 'Einsatzabteilung', 'sort_order' => 1])
        ->assertHasNoTableActionErrors();

    $group = Group::where('name', 'Einsatzabteilung')->sole();
    expect($group->ext_location_id)->toBe($south->id);

    Livewire::actingAs($northAdmin)
        ->test(GroupsRelationManager::class, ['ownerRecord' => $north, 'pageClass' => EditLocation::class])
        ->assertDontSee('Einsatzabteilung');
});

it('lets Standort-Admins assign members to multiple local Abteilungen without changing other Standorte', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $admin = memberAt($south, 'admin');
    $member = memberAt($south);
    $first = $south->groups()->create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $second = $south->groups()->create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $outside = $north->groups()->create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $member->groups()->attach([$second->id, $outside->id]);

    Livewire::actingAs($admin)
        ->test(MembersRelationManager::class, ['ownerRecord' => $south, 'pageClass' => EditLocation::class])
        ->callTableAction('assignGroups', $member, data: ['groups' => [$first->id, $second->id]])
        ->assertHasNoTableActionErrors()
        ->callTableAction('assignGroups', $member, data: ['groups' => [$first->id]])
        ->assertHasNoTableActionErrors();

    auth()->logout();
    expect($member->fresh()->groups()->pluck('groups.id')->all())
        ->toEqualCanonicalizing([$first->id, $outside->id]);
});

it('blocks writing into a foreign Standort', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');

    $this->actingAs(memberAt($south));

    expect(fn () => Vehicle::factory()->create(['ext_location_id' => $north->id]))->toThrow(AuthorizationException::class);
});

it('lets Standort-Admins into the admin panel but not global admins areas', function () {
    $south = station('Wache Süd');
    $admin = memberAt($south, 'admin');
    $member = memberAt($south);

    $this->actingAs($admin)->get('/admin/standorte')->assertOk();
    $this->actingAs($admin)->get('/admin/kameraden')->assertForbidden();
    $this->actingAs($member)->get('/admin/standorte')->assertForbidden();
});

it('switches the current Standort only to an accessible one', function () {
    $south = station('Wache Süd');
    $north = station('Wache Nord');
    $user = memberAt($south);

    $this->actingAs($user)->post('/standort/wechseln', ['location_id' => $north->id])->assertForbidden();
    $this->actingAs($user)->post('/standort/wechseln', ['location_id' => $south->id])->assertRedirect();
});
