<?php

use App\Models\User;
use Extensions\Locations\Filament\Resources\LocationResource\Pages\EditLocation;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers\MembersRelationManager;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers\VehiclesRelationManager;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

it('manages members and moves vehicles from the Standort page', function () {
    $admin = User::factory()->admin()->create();
    $l = Location::create(['name' => 'Wache Süd', 'slug' => 'sued']);
    $o = Location::create(['name' => 'Wache Nord', 'slug' => 'nord']);
    $u = User::factory()->create();
    $l->members()->attach($admin, ['role' => 'admin']);
    $v = Vehicle::factory()->create(['ext_location_id' => $l->id]);
    $g = Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $l, 'pageClass' => EditLocation::class])->assertSuccessful();
    Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $l, 'pageClass' => EditLocation::class])->mountTableAction('attach')->assertSuccessful();
    $g->callTableAction('attach', data: ['recordId' => $u->id, 'role' => 'member'])->assertHasNoTableActionErrors();
    Livewire::actingAs($admin)->test(VehiclesRelationManager::class, ['ownerRecord' => $l, 'pageClass' => EditLocation::class])->assertSuccessful()
        ->callTableAction('moveLocation', $v, data: ['location_id' => $o->id])->assertHasNoTableActionErrors();
    expect($v->fresh()->ext_location_id)->toBe($o->id);
    Livewire::actingAs($admin)->test(VehiclesRelationManager::class, ['ownerRecord' => $o, 'pageClass' => EditLocation::class])->mountTableAction('associate')->assertSuccessful();
});

it('lets a Standort-Admin add an existing member from another Standort', function () {
    $location = Location::create(['name' => 'Wache Süd', 'slug' => 'sued']);
    $otherLocation = Location::create(['name' => 'Wache Nord', 'slug' => 'nord']);
    $admin = User::factory()->create(['ext_location_id' => $location->id]);
    $admin->locations()->updateExistingPivot($location->id, ['role' => 'admin']);
    $member = User::factory()->create(['ext_location_id' => $otherLocation->id]);

    $access = app(LocationAccess::class);
    expect($access->canSee($member, $location->id))->toBeFalse();

    Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $location, 'pageClass' => EditLocation::class])
        ->callTableAction('attach', data: ['recordId' => $member->id, 'role' => 'member'])
        ->assertHasNoTableActionErrors();

    expect($location->members()->whereKey($member->id)->exists())->toBeTrue()
        ->and($access->canSee($member, $location->id))->toBeTrue();
});
