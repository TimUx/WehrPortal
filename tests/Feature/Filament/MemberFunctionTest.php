<?php

use App\Filament\Resources\GroupResource;
use App\Filament\Resources\MemberFunctionResource;
use App\Filament\Resources\MemberFunctionResource\Pages\ManageMemberFunctions;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());
});

test('groups are called Abteilungen and functions have their own entry', function () {
    expect(GroupResource::getNavigationLabel())->toBe('Abteilungen')
        ->and(GroupResource::getPluralModelLabel())->toBe('Abteilungen')
        ->and(MemberFunctionResource::getNavigationLabel())->toBe('Funktionen');
});

test('a function is created with its sub-functions', function () {
    Livewire::test(ManageMemberFunctions::class)
        ->callAction('create', data: [
            'name' => 'Jugendwart',
            'children' => [['name' => 'Stellvertretung'], ['name' => 'Betreuer']],
        ])
        ->assertHasNoFormErrors();

    $function = MemberFunction::topLevel()->sole();
    expect($function->children->pluck('name')->all())->toBe(['Stellvertretung', 'Betreuer'])
        ->and($function->children->first()->fullLabel())->toBe('Jugendwart › Stellvertretung');
});

test('functions can be reordered by drag and drop', function () {
    $a = MemberFunction::create(['name' => 'Jugendwart', 'sort_order' => 1]);
    $b = MemberFunction::create(['name' => 'Gerätewart', 'sort_order' => 2]);

    Livewire::test(ManageMemberFunctions::class)
        ->call('toggleTableReordering')
        ->call('reorderTable', [(string) $b->id, (string) $a->id]);

    expect(MemberFunction::topLevel()->orderBy('sort_order')->pluck('name')->all())->toBe(['Gerätewart', 'Jugendwart']);
});

test('members get Abteilungen and functions in their member data', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $warden = MemberFunction::create(['name' => 'Jugendwart']);
    $deputy = MemberFunction::create(['parent_id' => $warden->id, 'name' => 'Stellvertretung']);
    $device = MemberFunction::create(['name' => 'Gerätewart']);
    $member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);

    Livewire::test(EditUser::class, ['record' => $member->getKey()])
        ->fillForm(['groups' => [$youth->id], 'functions' => [$deputy->id, $device->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->groups->pluck('name')->all())->toBe(['Jugendfeuerwehr'])
        ->and($member->functions->pluck('name')->sort()->values()->all())->toBe(['Gerätewart', 'Stellvertretung']);
});

test('a function with sub-functions is not offered itself', function () {
    $warden = MemberFunction::create(['name' => 'Jugendwart']);
    MemberFunction::create(['parent_id' => $warden->id, 'name' => 'Stellvertretung']);
    MemberFunction::create(['name' => 'Gerätewart']);

    expect(MemberFunction::assignable()->orderBy('name')->pluck('name')->all())->toBe(['Gerätewart', 'Stellvertretung']);
});

test('deleting a function removes it and its sub-functions from the members', function () {
    $warden = MemberFunction::create(['name' => 'Jugendwart']);
    $deputy = MemberFunction::create(['parent_id' => $warden->id, 'name' => 'Stellvertretung']);
    $member = User::factory()->create();
    $member->functions()->attach($deputy);

    $warden->delete();

    expect(MemberFunction::count())->toBe(0)->and($member->functions()->count())->toBe(0);
});

test('the profile shows Abteilungen and functions', function () {
    $member = User::factory()->create();
    $member->groups()->attach(Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]));
    $warden = MemberFunction::create(['name' => 'Jugendwart']);
    $member->functions()->attach(MemberFunction::create(['parent_id' => $warden->id, 'name' => 'Stellvertretung']));

    $this->actingAs($member)->get(route('profile'))
        ->assertSee('Abteilungen')->assertSee('Jugendfeuerwehr')
        ->assertSee('Funktionen')->assertSee('Jugendwart › Stellvertretung');
});

test('the migration turns the fixed department into Abteilung memberships', function () {
    $migration = require base_path('database/migrations/2026_10_13_100000_move_member_department_to_groups.php');
    $migration->down();

    $existing = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $youth = User::factory()->create();
    $active = User::factory()->create();
    $none = User::factory()->create();
    DB::table('users')->where('id', $youth->id)->update(['department' => 'youth_brigade']);
    DB::table('users')->where('id', $active->id)->update(['department' => 'active_service']);

    $migration->up();

    expect(Schema::hasColumn('users', 'department'))->toBeFalse();
    expect($youth->refresh()->groups->pluck('id')->all())->toBe([$existing->id]);
    expect($active->refresh()->groups->pluck('name')->all())->toBe(['Aktiver Dienst']);
    expect($none->refresh()->groups)->toBeEmpty();
    expect(Group::where('name', 'Jugendfeuerwehr')->count())->toBe(1)
        ->and(Group::where('name', 'Musikzug')->exists())->toBeFalse();
});

test('the function search finds sub-functions by the name of their function too', function () {
    $warden = MemberFunction::create(['name' => 'Jugendwart']);
    MemberFunction::create(['parent_id' => $warden->id, 'name' => 'Stellvertretung']);
    MemberFunction::create(['name' => 'Gerätewart']);

    $form = Livewire::test(EditUser::class, ['record' => User::factory()->create(['phone' => null])->getKey()])->instance()->form;
    $select = collect($form->getFlatComponents())->first(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'functions');

    expect(array_values($select->getSearchResults('jugend')))->toBe(['Jugendwart › Stellvertretung'])
        ->and(array_values($select->getSearchResults('GERÄTE')))->toBe(['Gerätewart'])
        ->and(array_values($select->getSearchResults('stell')))->toBe(['Jugendwart › Stellvertretung']);
});
