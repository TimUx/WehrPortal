<?php

use App\Enums\MemberStatus;
use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use App\Services\ModuleRegistry;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages\CreateTemplate;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource\Pages\EditTemplate;
use Extensions\Anwesenheit\Filament\SettingsPage;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Models\AttendanceTemplate;
use Extensions\Anwesenheit\Services\AttendanceService;
use Extensions\Locations\Filament\Resources\LocationResource\Pages\EditLocation;
use Extensions\Locations\Filament\Resources\LocationResource\RelationManagers\ModuleRolesRelationManager;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\LocationModuleRole;
use Extensions\Locations\Services\LegacyModuleRoleInventory;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ProtectionManifest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.scoped_role_modules' => ['anwesenheit']]);
    Module::where('key', 'anwesenheit')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->roles = app(LocationModuleRoles::class);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'role-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'role-sued']);
    $this->member = User::factory()->create(['ext_location_id' => $this->a->id]);
    DB::table('x_location_user')->where('user_id', $this->member->id)->delete();
    DB::table('x_location_user')->updateOrInsert(
        ['location_id' => $this->b->id, 'user_id' => $this->member->id],
        ['role' => 'admin'],
    );
    $this->grant = fn (?string $role = 'verwalter', ?Location $at = null, ?string $expires = null) => $this->roles->grant(
        $this->admin, ($at ?? $this->a)->id, $this->member->id, 'anwesenheit', $role, 'Ausdrückliche Testzuweisung', $expires);
});

it('requires a current global admin for grants and revokes, not a location admin', function () {
    expect(fn () => $this->roles->grant($this->member, $this->b->id, $this->member->id, 'anwesenheit', 'verwalter', 'Test'))
        ->toThrow(HttpException::class);
    $grant = ($this->grant)();
    expect(fn () => $this->roles->revoke($this->member, $grant->id, $this->a->id, 'Test'))
        ->toThrow(HttpException::class);
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Test'))
        ->toThrow(HttpException::class);
});

it('keeps immutable grant history and explicit one-way revocation before regrant', function () {
    $grant = ($this->grant)();
    expect(fn () => ($this->grant)())->toThrow(ValidationException::class);
    $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Aufgabe beendet');
    expect($grant->fresh()->reason)->toBe('Ausdrückliche Testzuweisung')
        ->and($grant->fresh()->revoke_reason)->toBe('Aufgabe beendet');
    ($this->grant)(null);
    expect(LocationModuleRole::count())->toBe(2)
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeFalse()
        ->and($this->member->canUseModule('anwesenheit'))->toBeTrue();
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Nochmals'))->toThrow(ValidationException::class);
});

it('validates revocation reason and owner at action time without changing history', function () {
    $grant = ($this->grant)();
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, ' '))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, str_repeat('x', 501)))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->b->id, 'Andere Wache'))
        ->toThrow(ModelNotFoundException::class);
    expect($grant->fresh()->revoked_at)->toBeNull();
});

it('requires explicit replacement even for expired grants', function () {
    ($this->grant)('verwalter', $this->a, now()->addMinute()->toIso8601String());
    $this->travel(1)->minutes();
    expect(fn () => ($this->grant)())->toThrow(ValidationException::class);
});

it('permanently revokes grants when raw membership removal eliminates the final source', function (string $source) {
    $location = $source === 'primary' ? $this->a : $this->b;
    $grant = ($this->grant)('verwalter', $location);
    if ($source === 'primary') {
        DB::table('users')->where('id', $this->member->id)->update(['ext_location_id' => null]);
    } else {
        DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $location->id)->delete();
    }
    $closed = $grant->fresh();
    expect($closed->revoked_at)->not->toBeNull()
        ->and($closed->revoked_by)->toBeNull()
        ->and($closed->revoked_source)->toBe('membership')
        ->and($closed->revoke_reason)->toContain('Standortmitgliedschaft')
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $location->id))->toBeFalse();
    if ($source === 'primary') {
        DB::table('users')->where('id', $this->member->id)->update(['ext_location_id' => $location->id]);
    } else {
        DB::table('x_location_user')->updateOrInsert(
            ['user_id' => $this->member->id, 'location_id' => $location->id],
            ['role' => 'member'],
        );
    }
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $location->id))->toBeFalse();
    ($this->grant)('verwalter', $location);
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $location->id))->toBeTrue()
        ->and(LocationModuleRole::count())->toBe(2)
        ->and($grant->fresh()->revoked_at)->toEqual($closed->revoked_at);
})->with(['primary', 'pivot']);

it('retains a grant until both overlapping membership sources are removed', function (string $first) {
    DB::table('x_location_user')->updateOrInsert(
        ['user_id' => $this->member->id, 'location_id' => $this->a->id],
        ['role' => 'member'],
    );
    $grant = ($this->grant)();
    $removePrimary = fn () => DB::table('users')->where('id', $this->member->id)->update(['ext_location_id' => $this->b->id]);
    $removePivot = fn () => DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->a->id)->delete();
    $first === 'primary' ? $removePrimary() : $removePivot();
    expect($grant->fresh()->revoked_at)->toBeNull()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeTrue();
    $first === 'primary' ? $removePivot() : $removePrimary();
    expect($grant->fresh()->revoked_source)->toBe('membership')
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeFalse();
})->with(['primary', 'pivot']);

it('also closes grants when a pivot membership is moved through raw updates', function (string $column) {
    $grant = ($this->grant)('verwalter', $this->b);
    if ($column === 'location_id') {
        $value = $this->a->id;
    } else {
        $destination = User::factory()->create(['ext_location_id' => $this->b->id]);
        $destination->locations()->detach();
        $value = $destination->id;
    }
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->b->id)->update([$column => $value]);
    expect($grant->fresh()->revoked_source)->toBe('membership')
        ->and($grant->fresh()->revoked_by)->toBeNull();
})->with(['location_id', 'user_id']);

it('preserves a manual revocation when membership is removed later', function () {
    $grant = ($this->grant)('verwalter', $this->b);
    $this->roles->revoke($this->admin, $grant->id, $this->b->id, 'Ausdrücklicher Entzug');
    $before = $grant->fresh()->getAttributes();
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->b->id)->delete();
    expect($grant->fresh()->getAttributes())->toBe($before)
        ->and($grant->fresh()->revoked_source)->toBe('admin');
});

it('does not confuse a location-admin downgrade with membership removal', function () {
    $grant = ($this->grant)('verwalter', $this->b);
    DB::table('x_location_user')->where('user_id', $this->member->id)->where('location_id', $this->b->id)->update(['role' => 'member']);
    expect($grant->fresh()->revoked_at)->toBeNull()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->b->id))->toBeTrue();
});

it('rejects incomplete or falsely attributed revocation audit metadata', function (string $invalid) {
    $grant = ($this->grant)();
    $changes = ['revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'Prüfung', 'revoked_source' => 'admin'];
    match ($invalid) {
        'missing actor' => $changes['revoked_by'] = null,
        'false system actor' => $changes['revoked_source'] = 'membership',
        'missing source' => $changes['revoked_source'] = null,
        'unknown source' => $changes['revoked_source'] = 'unknown',
        'missing reason' => $changes['revoke_reason'] = null,
    };
    expect(fn () => DB::transaction(fn () => DB::table('x_location_module_roles')->where('id', $grant->id)->update($changes)))
        ->toThrow(QueryException::class);
    expect($grant->fresh()->revoked_at)->toBeNull();
})->with(['missing actor', 'false system actor', 'missing source', 'unknown source', 'missing reason']);

it('protects historical grants from database mutation and deletion', function (string $operation) {
    $grant = ($this->grant)();
    expect(fn () => DB::transaction(fn () => match ($operation) {
        'delete' => DB::table('x_location_module_roles')->where('id', $grant->id)->delete(),
        'edit' => DB::table('x_location_module_roles')->where('id', $grant->id)->update(['reason' => 'Rewrite']),
        'duplicate' => DB::table('x_location_module_roles')->insert(collect($grant->getAttributes())->except('id')->all()),
    }))->toThrow(QueryException::class);
})->with(['delete', 'edit', 'duplicate']);

it('protects every historical member identity from deletion or privilege transfer', function (string $reference) {
    $grant = ($this->grant)();
    $revoker = User::factory()->create(['is_admin' => true]);
    if ($reference === 'revoked_by') {
        $this->roles->revoke($revoker, $grant->id, $this->a->id, 'Aufgabe beendet');
    }
    $source = match ($reference) {
        'user_id' => $this->member,
        'granted_by' => $this->admin,
        'revoked_by' => $revoker,
    };
    $destination = User::factory()->create(['ext_location_id' => $this->a->id]);
    $operator = User::factory()->create(['is_admin' => true]);
    $list = AttendanceList::create(['title' => 'Unverändert', 'ext_location_id' => $this->a->id, 'created_by' => $source->id]);
    $history = DB::table('x_location_module_roles')->get()->all();
    $members = DB::table('users')->whereIn('id', [$source->id, $destination->id])->orderBy('id')->get()->all();
    $removal = app(MemberRemoval::class);

    expect($removal->references()->where('table', 'x_location_module_roles'))->toBeEmpty()
        ->and(fn () => $removal->transferAndDelete($source, $destination, $operator))->toThrow(ValidationException::class)
        ->and(fn () => $source->delete())->toThrow(ValidationException::class);

    expect(DB::table('x_location_module_roles')->get()->all())->toEqual($history)
        ->and(DB::table('users')->whereIn('id', [$source->id, $destination->id])->orderBy('id')->get()->all())->toEqual($members)
        ->and($list->fresh()->created_by)->toBe($source->id)
        ->and($destination->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeFalse();
})->with(['user_id', 'granted_by', 'revoked_by']);

it('retains deletion protection with the extension disabled while allowing inactive or left status', function () {
    ($this->grant)();
    config(['locations.scoped_role_modules' => [], 'locations.enabled' => false]);
    expect(fn () => $this->member->delete())->toThrow(ValidationException::class);
    $this->member->update(['status' => MemberStatus::Inactive]);
    expect($this->member->fresh()->is_active)->toBeFalse();
    $this->member->update(['status' => MemberStatus::Left]);
    expect($this->member->fresh()->status)->toBe(MemberStatus::Left);
});

it('validates grant module role membership activity reason and expiry', function (string $invalid) {
    $module = 'anwesenheit';
    $role = 'verwalter';
    $reason = 'Testzuweisung';
    $expires = null;
    match ($invalid) {
        'module' => $module = 'unknown',
        'role' => $role = 'unknown',
        'reason' => $reason = str_repeat('a', 501),
        'blank' => $reason = ' ',
        'expiry' => $expires = now()->subSecond()->toIso8601String(),
        'malformed expiry' => $expires = 'not-a-date',
        'location' => $this->a->update(['is_active' => false]),
        'member' => $this->member->update(['is_active' => false]),
        'membership' => DB::table('users')->where('id', $this->member->id)->update(['ext_location_id' => null]),
        'inactive module' => Module::where('key', 'anwesenheit')->update(['is_active' => false]),
    };
    expect(fn () => $this->roles->grant($this->admin, $this->a->id, $this->member->id, $module, $role, $reason, $expires))
        ->toThrow(ValidationException::class);
    expect(LocationModuleRole::count())->toBe(0);
})->with(['module', 'role', 'reason', 'blank', 'expiry', 'malformed expiry', 'location', 'member', 'membership', 'inactive module']);

it('uses explicit targets without auth or session and never borrows another locations authority', function () {
    ($this->grant)();
    ($this->grant)(null, $this->b);
    expect(auth()->check())->toBeFalse()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter'))->toBeFalse()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeTrue()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->b->id))->toBeFalse()
        ->and($this->member->hasAnyModuleRole('anwesenheit', 'verwalter'))->toBeTrue()
        ->and(AttendanceService::canManageAt($this->member, $this->b->id))->toBeFalse();
    $this->actingAs($this->admin);
    session(['ext_location_id' => $this->a->id]);
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter'))->toBeFalse();
});

it('denies immediately on expiry removal inactivity or module disable without cached authority', function (string $change) {
    ($this->grant)('verwalter', $this->b, now()->addMinute()->toIso8601String());
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->b->id))->toBeTrue();
    match ($change) {
        'expiry' => $this->travel(1)->minutes(),
        'membership' => DB::table('x_location_user')->where('user_id', $this->member->id)->delete(),
        'user' => DB::table('users')->where('id', $this->member->id)->update(['is_active' => false]),
        'location' => $this->b->update(['is_active' => false]),
        'module' => Module::where('key', 'anwesenheit')->update(['is_active' => false]),
    };
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->b->id))->toBeFalse();
})->with(['expiry', 'membership', 'user', 'location', 'module']);

it('does not fall back to legacy roles or location admin and preserves compatibility when disabled', function () {
    $this->member->moduleAccess()->create(['module' => 'anwesenheit', 'role' => 'verwalter']);
    expect($this->member->canUseModule('anwesenheit'))->toBeTrue()
        ->and($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeFalse()
        ->and(AttendanceService::canManage($this->member))->toBeFalse()
        ->and(AttendanceService::canManageAt($this->member, $this->b->id))->toBeFalse();
    config(['locations.scoped_role_modules' => []]);
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter'))->toBeTrue()
        ->and(AttendanceService::canManageAt($this->member, $this->b->id))->toBeTrue();
});

it('fails closed for unsupported module activation and unavailable adapters', function (mixed $modules) {
    config(['locations.scoped_role_modules' => $modules]);
    expect(fn () => $this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))
        ->toThrow(InvalidArgumentException::class);
})->with([[['kalender']], [['anwesenheit', 'fahrzeugbuchung']], ['anwesenheit']]);

it('normalizes operator environment activation without dropping unsupported tokens', function (string $value, array $expected) {
    $environment = Env::getRepository();
    $original = $environment->get('LOCATIONS_SCOPED_ROLE_MODULES');
    $environment->set('LOCATIONS_SCOPED_ROLE_MODULES', $value);
    try {
        $settings = require base_path('extensions/Locations/config/locations.php');
        expect($settings['scoped_role_modules'])->toBe($expected);
        config(['locations.scoped_role_modules' => $settings['scoped_role_modules']]);
        if (in_array('kalender', $expected, true)) {
            expect(fn () => $this->roles->configuredModules())->toThrow(InvalidArgumentException::class);
        } else {
            expect($this->roles->configuredModules())->toBe($expected);
        }
    } finally {
        $original === null
            ? $environment->clear('LOCATIONS_SCOPED_ROLE_MODULES')
            : $environment->set('LOCATIONS_SCOPED_ROLE_MODULES', $original);
    }
})->with([
    'empty default' => ['', []],
    'normalized and deduplicated' => [' Anwesenheit , ANWESENHEIT ,, ', ['anwesenheit']],
    'unknown token retained and rejected' => ['Anwesenheit, Kalender', ['anwesenheit', 'kalender']],
]);

it('allows preparing attendance grants before activation without enabling authority', function () {
    config(['locations.scoped_role_modules' => []]);
    ($this->grant)();
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeFalse();
    config(['locations.scoped_role_modules' => ['anwesenheit']]);
    expect($this->member->hasModuleRole('anwesenheit', 'verwalter', $this->a->id))->toBeTrue();
});

it('rejects registered but unported modules when preparing grants', function () {
    Module::where('key', 'kalender')->update(['is_active' => true]);
    expect(fn () => $this->roles->grant($this->admin, $this->a->id, $this->member->id, 'kalender', 'terminverwalter', 'Nicht vorbereitet'))
        ->toThrow(ValidationException::class);
    expect(LocationModuleRole::count())->toBe(0);
});

it('preserves creator participant semantics without making role holders managers elsewhere and revokes actions immediately', function () {
    $owner = User::factory()->create(['ext_location_id' => $this->a->id]);
    $listA = AttendanceList::create(['title' => 'Nord', 'ext_location_id' => $this->a->id, 'created_by' => $owner->id, 'status' => 'submitted']);
    $listB = AttendanceList::create(['title' => 'Süd', 'ext_location_id' => $this->b->id, 'created_by' => $owner->id, 'status' => 'submitted']);
    $grant = ($this->grant)();
    ($this->grant)(null, $this->b);
    $service = app(AttendanceService::class);
    expect(AttendanceService::canEdit($this->member, $listA))->toBeTrue()
        ->and(AttendanceService::canResend($this->member, $listA))->toBeTrue()
        ->and(AttendanceService::canSee($this->member, $listB))->toBeFalse()
        ->and($service->visibleTo($this->member)->pluck('id')->all())->toBe([$listA->id]);
    $listB->participants()->attach($this->member);
    expect(AttendanceService::canSee($this->member, $listB))->toBeTrue()
        ->and(AttendanceService::canEdit($this->member, $listB))->toBeFalse();
    $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Aufgabe beendet');
    expect(AttendanceService::canEdit($this->member, $listA))->toBeFalse()
        ->and(AttendanceService::canResend($this->member, $listA))->toBeFalse()
        ->and($service->visibleTo($this->member)->pluck('id')->all())->toBe([$listB->id]);
    expect(fn () => $service->save($this->member, $listA, [], []))->toThrow(ValidationException::class);
    expect(fn () => $service->delete($this->member, $listA))->toThrow(ValidationException::class);
    expect(fn () => $service->send($this->member, $listA))->toThrow(HttpException::class);
    Livewire::actingAs($this->member)->test(SettingsPage::class)->assertForbidden();
});

it('retains creator edits only for drafts and denies implicit or removed creation targets', function () {
    ($this->grant)(null);
    $draft = AttendanceList::create(['title' => 'Eigener Entwurf', 'ext_location_id' => $this->a->id, 'created_by' => $this->member->id]);
    expect(AttendanceService::canEdit($this->member, $draft))->toBeTrue();
    $draft->update(['status' => 'submitted']);
    expect(AttendanceService::canEdit($this->member, $draft))->toBeFalse()
        ->and(AttendanceService::canResend($this->member, $draft))->toBeTrue();
    $service = app(AttendanceService::class);
    $service->locationsFor($this->member);
    DB::table('x_location_user')->where('user_id', $this->member->id)->delete();
    expect($service->locationsFor($this->member))->not->toHaveKey($this->b->id);
    expect(fn () => $service->save($this->member, null, ['title' => 'Ohne Ziel'], []))->toThrow(ValidationException::class);
    expect(fn () => $service->save($this->member, null, ['title' => 'Entfernter Standort', 'ext_location_id' => $this->b->id], []))
        ->toThrow(ValidationException::class);
});

it('keeps all data accessible to active global admins without membership or grants', function () {
    $list = AttendanceList::create(['title' => 'Global', 'ext_location_id' => $this->b->id, 'created_by' => $this->member->id, 'status' => 'submitted']);
    $this->b->update(['is_active' => false]);
    expect($this->admin->hasModuleRole('anwesenheit', 'verwalter'))->toBeTrue()
        ->and(AttendanceService::canEdit($this->admin, $list))->toBeTrue()
        ->and(app(AttendanceService::class)->visibleTo($this->admin)->pluck('id')->all())->toBe([$list->id]);
});

it('inventories zero single and multiple candidates without writing or choosing defaults', function () {
    $this->member->moduleAccess()->create(['module' => 'anwesenheit', 'role' => 'verwalter']);
    $single = User::factory()->create(['ext_location_id' => $this->a->id]);
    $zero = User::factory()->create();
    DB::table('users')->where('id', $zero->id)->update(['ext_location_id' => null]);
    $zero->locations()->detach();
    foreach ([$single, $zero] as $member) {
        $member->moduleAccess()->create(['module' => 'anwesenheit']);
    }
    $rows = collect(app(LegacyModuleRoleInventory::class)->rows())->where('module', 'anwesenheit')->keyBy('user_id');
    expect($rows[$this->member->id]['classification'])->toBe('multiple')
        ->and($rows[$single->id]['classification'])->toBe('single')
        ->and($rows[$zero->id]['classification'])->toBe('zero')
        ->and(LocationModuleRole::count())->toBe(0);
    $this->artisan('locations:inventory-module-roles')->assertSuccessful();
    expect(LocationModuleRole::count())->toBe(0);
});

it('has a classified fresh migration with explicit foreign keys and pending broad enforcement', function () {
    $manifest = config('locations.protection');
    expect(app(ProtectionManifest::class)->violations($manifest))->toBe([])
        ->and($manifest['tables']['x_location_module_roles']['enforcement']['status'])->toBe('pending_verification');
});

it('exposes role history through the existing location page only to global admins', function () {
    expect(EditLocation::getResource()::getRelations())->toContain(ModuleRolesRelationManager::class)
        ->and(ModuleRolesRelationManager::canViewForRecord($this->a, EditLocation::class))->toBeFalse();
    $this->actingAs($this->member);
    expect(ModuleRolesRelationManager::canViewForRecord($this->b, EditLocation::class))->toBeFalse();
    Livewire::actingAs($this->admin)->test(ModuleRolesRelationManager::class, ['ownerRecord' => $this->a, 'pageClass' => EditLocation::class])
        ->callTableAction('grant', data: ['user_id' => $this->member->id, 'module' => 'anwesenheit', 'role' => 'verwalter', 'reason' => 'Verantwortung übernommen'])
        ->assertHasNoTableActionErrors();
    $grant = LocationModuleRole::sole();
    Livewire::actingAs($this->admin)->test(ModuleRolesRelationManager::class, ['ownerRecord' => $this->a, 'pageClass' => EditLocation::class])
        ->callTableAction('revoke', $grant, data: ['reason' => 'Verantwortung beendet'])->assertHasNoTableActionErrors();
    expect($grant->fresh()->revoked_at)->not->toBeNull();
});

it('reauthorizes an already mounted role manager after the global admin is demoted', function () {
    $component = Livewire::actingAs($this->admin)->test(ModuleRolesRelationManager::class, ['ownerRecord' => $this->a, 'pageClass' => EditLocation::class])
        ->mountTableAction('grant')
        ->setTableActionData(['user_id' => $this->member->id, 'module' => 'anwesenheit', 'role' => 'verwalter', 'reason' => 'Nicht mehr erlaubt']);
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    $component->callMountedTableAction()->assertForbidden();
    expect(LocationModuleRole::count())->toBe(0);
});

it('reauthorizes attendance template target writes and rejects forged locations', function () {
    ($this->grant)();
    ($this->grant)(null, $this->b);
    Livewire::actingAs($this->member)->test(CreateTemplate::class)
        ->fillForm(['name' => 'Nicht erlaubt', 'kind' => 'uebung', 'ext_location_id' => $this->b->id])
        ->call('create')->assertHasFormErrors(['ext_location_id']);
    $template = AttendanceTemplate::create(['name' => 'Vorlage', 'kind' => 'uebung', 'ext_location_id' => $this->a->id]);
    $component = Livewire::actingAs($this->member)->test(EditTemplate::class, ['record' => $template->id])
        ->assertOk()->fillForm(['name' => 'Nach Entzug']);
    $grant = LocationModuleRole::where('role', 'verwalter')->sole();
    $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Aufgabe beendet');
    $component->call('save')->assertNotFound();
    expect($template->fresh()->name)->toBe('Vorlage');
});
