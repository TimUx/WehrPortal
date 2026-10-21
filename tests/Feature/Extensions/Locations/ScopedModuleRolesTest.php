<?php

use App\Models\Module;
use App\Models\User;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['locations.scoped_role_modules' => ['anwesenheit']]);
    Module::where('key', 'anwesenheit')->update(['is_active' => true, 'auto_assign' => false]);
    app(\App\Services\ModuleRegistry::class)->clearCache();
    $this->roles = app(LocationModuleRoles::class);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'role-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'role-sued']);
    $this->member = User::factory()->create(['ext_location_id' => $this->a->id]);
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->member->id, 'role' => 'admin']);
    $this->grant = fn (?string $role = 'verwalter', ?Location $at = null, ?string $expires = null) => $this->roles->grant(
        $this->admin, ($at ?? $this->a)->id, $this->member->id, 'anwesenheit', $role, 'Ausdrückliche Testzuweisung', $expires);
});

it('requires a current global admin for grants and revokes, not a location admin', function () {
    expect(fn () => $this->roles->grant($this->member, $this->b->id, $this->member->id, 'anwesenheit', 'verwalter', 'Test'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $grant = ($this->grant)();
    expect(fn () => $this->roles->revoke($this->member, $grant->id, $this->a->id, 'Test'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect(fn () => $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Test'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
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

it('protects historical grants from database mutation and deletion', function (string $operation) {
    $grant = ($this->grant)();
    expect(fn () => DB::transaction(fn () => match ($operation) {
        'delete' => DB::table('x_location_module_roles')->where('id', $grant->id)->delete(),
        'edit' => DB::table('x_location_module_roles')->where('id', $grant->id)->update(['reason' => 'Rewrite']),
        'duplicate' => DB::table('x_location_module_roles')->insert(collect($grant->getAttributes())->except('id')->all()),
    }))->toThrow(QueryException::class);
})->with(['delete', 'edit', 'duplicate']);

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
})->with([['kalender'], ['anwesenheit', 'fahrzeugbuchung'], 'anwesenheit']);

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
    Livewire::actingAs($this->member)->test(SettingsPage::class)->assertForbidden();
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

it('reauthorizes attendance template target writes and rejects forged locations', function () {
    ($this->grant)();
    ($this->grant)(null, $this->b);
    Livewire::actingAs($this->member)->test(CreateTemplate::class)
        ->fillForm(['name' => 'Nicht erlaubt', 'kind' => 'uebung', 'ext_location_id' => $this->b->id])
        ->call('create')->assertHasFormErrors('ext_location_id');
    $template = AttendanceTemplate::create(['name' => 'Vorlage', 'kind' => 'uebung', 'ext_location_id' => $this->a->id]);
    $component = Livewire::actingAs($this->member)->test(EditTemplate::class, ['record' => $template->id]);
    $grant = LocationModuleRole::where('role', 'verwalter')->sole();
    $this->roles->revoke($this->admin, $grant->id, $this->a->id, 'Aufgabe beendet');
    $component->fillForm(['name' => 'Nach Entzug'])->call('save')->assertForbidden();
    expect($template->fresh()->name)->toBe('Vorlage');
});
