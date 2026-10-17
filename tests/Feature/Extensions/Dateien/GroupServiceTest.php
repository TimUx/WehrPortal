<?php

use App\Models\Module;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->manager = filesMember('Hanna Hydrant');
    $this->member = filesMember('Bernd Blaulicht');
    $this->groups = app(GroupService::class);
});

it('allows administrators to create groups and delegate membership administration', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', null);
    $this->groups->setMember($this->admin, $group, $this->manager->id, true);
    $this->groups->setMember($this->manager, $group, $this->member->id, false);

    expect(GroupService::canManage($this->manager, $group))->toBeTrue()
        ->and(GroupService::canManage($this->member, $group))->toBeFalse()
        ->and($group->members()->pluck('users.id')->all())->toContain($this->manager->id, $this->member->id);
    $this->groups->setMember($this->manager, $group, $this->member->id, null);
    expect($group->members()->whereKey($this->member->id)->exists())->toBeFalse();
});

it('does not turn a group manager into a file administrator or global group manager', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', null);
    $other = $this->groups->save($this->admin, null, 'Fahrzeugwarte', null);
    $this->groups->setMember($this->admin, $group, $this->manager->id, true);
    $files = app(FileService::class);
    $space = $files->createSpace($this->admin, ['name' => 'Archiv', 'ext_location_id' => Location::default()->id]);

    expect(GroupService::canManage($this->manager, $other))->toBeFalse()
        ->and($this->groups->groups($this->manager)->pluck('id')->all())->toBe([$group->id]);
    expect(fn () => $this->groups->setMember($this->manager, $other, $this->member->id, true))->toThrow(HttpException::class);
    expect(fn () => $this->groups->save($this->manager, $other, 'Übernommen', null))->toThrow(HttpException::class);
    expect(app(\Extensions\Dateien\Services\FileAccess::class)->can($this->manager, $space, 'manage'))->toBeFalse();
    expect($other->fresh()->name)->toBe('Fahrzeugwarte');
});

it('prevents ordinary members from creating groups or escalating group membership', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', null);
    $this->groups->setMember($this->admin, $group, $this->member->id, false);

    expect(fn () => $this->groups->save($this->member, null, 'Neue Gruppe', null))->toThrow(HttpException::class);
    expect(fn () => $this->groups->setMember($this->member, $group, $this->member->id, true))->toThrow(HttpException::class);
    expect(fn () => $this->groups->setMember($this->member, $group, $this->admin->id, null))->toThrow(HttpException::class);
    expect(FileGroup::count())->toBe(1)
        ->and(GroupService::canManage($this->member, $group))->toBeFalse();
});
