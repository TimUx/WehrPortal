<?php

use App\Filament\Pages\ModuleMembers;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Module::where('key', 'uebungsdienste')->update(['is_active' => true, 'auto_assign' => false]);
    Cache::flush();
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);

    $this->vorstand = MemberFunction::create(['name' => 'Vorstand']);
    $this->kassenwart = MemberFunction::create(['name' => 'Kassenwart', 'parent_id' => $this->vorstand->id]);
    $this->jugend = MemberFunction::create(['name' => 'Jugendwart']);

    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);   // Vorstand › Kassenwart
    $this->hanna->functions()->attach($this->kassenwart);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);  // Jugendwart only
    $this->bernd->functions()->attach($this->jugend);
    $this->leader = User::factory()->create(['name' => 'Wilma Wehrführung', 'phone' => null]);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
});

function planner(User $user): bool
{
    return DB::table('module_user')->where('user_id', $user->id)->where('module', 'uebungsdienste')->exists();
}

test('the Vorstand includes its sub-functions', function () {
    expect($this->hanna->isVorstand())->toBeTrue()->and($this->bernd->isVorstand())->toBeFalse();
});

test('the Vereinsvorstand is not the Übungsdienstplaner audience', function () {
    expect(app(ModuleRegistry::class)->isMeantFor('uebungsdienste', $this->hanna))->toBeFalse()
        ->and(app(ModuleRegistry::class)->isMeantFor('uebungsdienste', $this->leader))->toBeTrue();
});

test('unlocking someone outside the Wehrführung needs a confirmation on the module list', function () {
    $page = Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'uebungsdienste'])
        ->assertSee('Außerhalb der Zielgruppe „Übungsdienstleitung“')
        ->assertTableActionVisible('grant_outside', $this->hanna)
        ->assertTableActionVisible('grant_outside', $this->bernd)
        ->assertTableActionHidden('grant_outside', $this->leader);

    // The bulk action unlocks only Wehrführung without the tick
    $page->callTableBulkAction('grant', [$this->leader, $this->hanna, $this->bernd])->assertNotified();
    expect(planner($this->leader))->toBeTrue()->and(planner($this->bernd))->toBeFalse();

    $page->callTableAction('grant_outside', $this->bernd);
    expect(planner($this->bernd))->toBeTrue();
});

test('the bulk action unlocks others with the tick', function () {
    Livewire::actingAs($this->admin)->test(ModuleMembers::class, ['module' => 'uebungsdienste'])
        ->callTableBulkAction('grant', [$this->bernd], data: ['confirm_outside' => true]);

    expect(planner($this->bernd))->toBeTrue();
});

test('"automatisch freischalten" covers only the Wehrführung', function () {
    Module::where('key', 'uebungsdienste')->update(['auto_assign' => true]);
    Cache::flush();

    expect($this->leader->canUseModule('uebungsdienste'))->toBeTrue()
        ->and($this->hanna->canUseModule('uebungsdienste'))->toBeFalse()
        ->and($this->bernd->canUseModule('uebungsdienste'))->toBeFalse()
        ->and($this->admin->canUseModule('uebungsdienste'))->toBeTrue();
});

test('the Kamerad form asks for a confirmation outside the Wehrführung', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->bernd->getRouteKey()])
        ->fillForm(['module_access_uebungsdienste' => true])
        ->call('save')
        ->assertHasFormErrors(['module_confirm_uebungsdienste' => 'accepted']);
    expect(planner($this->bernd))->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $this->bernd->getRouteKey()])
        ->fillForm(['module_access_uebungsdienste' => true, 'module_confirm_uebungsdienste' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(planner($this->bernd))->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $this->leader->getRouteKey()])
        ->fillForm(['module_access_uebungsdienste' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(planner($this->leader))->toBeTrue();
});

test('filtering the Kameraden by a function includes its sub-functions', function () {
    $this->actingAs($this->admin);
    $chief = User::factory()->create(['name' => 'Walter Wehrführer']);
    $chief->functions()->attach($this->vorstand);

    Livewire::test(ListUsers::class)
        ->filterTable('functions', [$this->vorstand->id])
        ->assertCanSeeTableRecords([$this->hanna, $chief])
        ->assertCanNotSeeTableRecords([$this->bernd])
        ->resetTableFilters()
        ->filterTable('functions', [$this->kassenwart->id])
        ->assertCanSeeTableRecords([$this->hanna])
        ->assertCanNotSeeTableRecords([$chief, $this->bernd]);
});
