<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardDepartments;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

function locationWithSlug(string $name, string $slug): Location
{
    return Location::create(['name' => $name, 'slug' => $slug]);
}

it('creates the six standard departments for each location and allows additional departments', function () {
    $location = locationWithSlug('Wache Nord', 'standard-departments-nord');

    expect($location->groups()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(StandardDepartments::NAMES);

    $location->groups()->create(['name' => 'Musikzug']);
    expect($location->groups()->pluck('name'))->toContain('Musikzug');
});

it('provisions a writable location folder and supports department permissions on isolated subfolders', function () {
    config(['locations.enabled' => true]);
    $location = locationWithSlug('Wache Nord', 'files-location-nord');
    $otherLocation = locationWithSlug('Wache Süd', 'files-location-sued');
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Hanna Hydrant', 'ext_location_id' => $location->id]);
    $sameLocationColleague = User::factory()->create(['name' => 'Bernd Blaulicht', 'ext_location_id' => $location->id]);
    $outsideMember = User::factory()->create(['name' => 'Nina Nord', 'ext_location_id' => $otherLocation->id]);
    Notification::fake();
    $department = $location->groups()->where('name', 'Einsatzabteilung')->firstOrFail();
    $member->groups()->attach($department);

    Module::query()->where('key', 'dateien')->firstOrFail()->update(['is_active' => true, 'auto_assign' => false]);
    Cache::forget('modules_active_keys');

    $space = Space::query()->where('ext_location_id', $location->id)->where('is_location_default', true)->sole();
    $access = app(FileAccess::class);
    $files = app(FileService::class);

    expect($access->level($member, $space))->toBe('edit')
        ->and($access->level($outsideMember, $space))->toBeNull();

    $otherSpace = $files->createSpace($admin, ['name' => 'Weitere Ablage', 'ext_location_id' => $location->id]);
    expect($access->level($member, $otherSpace))->toBeNull();

    $this->actingAs($member)->get('/dateien')->assertOk();

    $folder = $files->createFolder($admin, $space, null, 'Nur Einsatzabteilung');
    $files->setPermission($admin, $folder, 'department', $department->id, 'read');

    expect($access->level($member, $folder))->toBe('read')
        ->and($folder->fresh()->inherit_permissions)->toBeFalse()
        ->and($access->level($sameLocationColleague, $folder))->toBeNull()
        ->and(Permission::query()->where('subject_type', 'department')->where('subject_id', $department->id)->exists())->toBeTrue();

    Module::query()->where('key', 'dateien')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->get('/dateien')->assertNotFound();
});

it('lets global admins create shared file areas and recipients see them in every browser layout', function () {
    config(['locations.enabled' => true]);
    $location = locationWithSlug('Wache Nord', 'shared-space-location');
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Hanna Hydrant', 'ext_location_id' => $location->id]);
    Notification::fake();

    Module::query()->where('key', 'dateien')->firstOrFail()->update(['is_active' => true, 'auto_assign' => false]);
    Cache::forget('modules_active_keys');
    $space = app(FileService::class)->createSpace($admin, ['name' => 'Gemeinsame Ausbildung']);
    app(FileService::class)->setPermission($admin, $space, 'user', $member->id, 'edit');

    Livewire::actingAs($admin)->test(Explorer::class)
        ->set('spaceName', 'Neue Dokumente')
        ->call('createSpace')
        ->assertHasNoErrors()
        ->assertSee('Ablage erstellt.');

    foreach (['list', 'table', 'tiles'] as $layout) {
        Livewire::actingAs($member)->test(Explorer::class)
            ->call('setLayout', $layout)
            ->assertSee('Gemeinsame Ausbildung')
            ->assertSee('Wache Nord');
    }
});
