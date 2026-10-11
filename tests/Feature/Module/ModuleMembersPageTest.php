<?php

use App\Filament\Pages\ManageModules;
use App\Filament\Pages\ModuleMembers;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    Module::where('key', 'lehrgaenge')->update(['is_active' => true, 'auto_assign' => false]);

    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->hanna->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
});

function hasAccess(User $user, string $module = 'lehrgaenge'): bool
{
    return DB::table('module_user')->where('user_id', $user->id)->where('module', $module)->exists();
}

test('"x Kameraden" on Module verwalten opens the list of the module', function () {
    Livewire::actingAs($this->admin)->test(ManageModules::class)
        ->assertSee(ModuleMembers::getUrl(['module' => 'lehrgaenge']), false);

    $this->actingAs($this->admin)->get(ModuleMembers::getUrl(['module' => 'lehrgaenge']))
        ->assertOk()->assertSee('Kameraden: Lehrgänge &amp; Qualifikationen', false);
    $this->actingAs($this->admin)->get('/admin/module/gibtsnicht/kameraden')->assertNotFound();
    $this->actingAs($this->bernd)->get(ModuleMembers::getUrl(['module' => 'lehrgaenge']))->assertForbidden();
});

test('the list shows unlocked and locked Kameraden and switches single ones', function () {
    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->assertCanSeeTableRecords([$this->hanna, $this->bernd])
        ->assertTableColumnStateSet('has_access', true, $this->hanna)
        ->assertTableColumnStateSet('has_access', false, $this->bernd)
        ->assertTableColumnStateSet('module_role', 'ausbildungsleiter', $this->hanna)
        ->call('updateTableColumnState', 'has_access', (string) $this->bernd->id, true)
        ->call('updateTableColumnState', 'has_access', (string) $this->hanna->id, false);

    expect(hasAccess($this->bernd))->toBeTrue()->and(hasAccess($this->hanna))->toBeFalse();
});

test('choosing a role also unlocks the module', function () {
    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->call('updateTableColumnState', 'module_role', (string) $this->bernd->id, 'wehrführung');

    expect(DB::table('module_user')->where('user_id', $this->bernd->id)->where('module', 'lehrgaenge')->value('role'))->toBe('wehrführung');
});

test('search and filters: unlocked, role, Abteilung, status', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->bernd->groups()->attach($youth);
    $left = User::factory()->create(['name' => 'Otto Ausgetreten', 'status' => 'left']);

    $page = Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge']);
    $page->assertCanNotSeeTableRecords([$left]);   // only active members by default

    $page->searchTable('Hanna')->assertCanSeeTableRecords([$this->hanna])->assertCanNotSeeTableRecords([$this->bernd]);
    $page->searchTable('')->filterTable('has_access', false)->assertCanSeeTableRecords([$this->bernd])->assertCanNotSeeTableRecords([$this->hanna]);
    $page->resetTableFilters()->filterTable('role', 'ausbildungsleiter')->assertCanSeeTableRecords([$this->hanna])->assertCanNotSeeTableRecords([$this->bernd]);
    $page->resetTableFilters()->filterTable('groups', [$youth->id])->assertCanSeeTableRecords([$this->bernd])->assertCanNotSeeTableRecords([$this->hanna]);
    $page->resetTableFilters()->filterTable('status', 'left')->assertCanSeeTableRecords([$left]);
});

test('a selection is unlocked or locked at once; existing roles stay', function () {
    $more = User::factory()->count(3)->create();

    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->callTableBulkAction('grant', [$this->hanna, $this->bernd, ...$more]);

    expect(hasAccess($this->bernd))->toBeTrue()
        ->and($more->every(fn (User $u) => hasAccess($u)))->toBeTrue()
        ->and(DB::table('module_user')->where('user_id', $this->hanna->id)->where('module', 'lehrgaenge')->value('role'))->toBe('ausbildungsleiter');

    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->callTableBulkAction('revoke', $more);

    expect($more->every(fn (User $u) => ! hasAccess($u)))->toBeTrue()->and(hasAccess($this->bernd))->toBeTrue();
});

test('the page says when everyone has access anyway', function () {
    Module::where('key', 'lehrgaenge')->update(['auto_assign' => true]);

    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->assertSee('alle Kameraden haben Zugang, auch ohne Haken');
});

test('the list sorts by unlocked', function () {
    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'lehrgaenge'])
        ->sortTable('has_access', 'desc')
        ->assertCanSeeTableRecords([$this->hanna, $this->bernd], inOrder: true);
});
