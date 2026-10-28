<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Notifications\PlanNotification;
use Modules\Schichtplan\src\Services\PlanLocationAccess;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\SignupService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'schichtplan')->update(['is_active' => true, 'auto_assign' => false]);
    Cache::forget('modules_active_keys');

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'schichtplan-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'schichtplan-sued']);
    $this->planner = User::factory()->create(['name' => 'Wanda Wehrführung', 'ext_location_id' => $this->north->id]);
    $this->otherSiteMember = User::factory()->create(['name' => 'Oskar Ortswehr', 'ext_location_id' => $this->south->id]);
    app(StandardRoleAssignments::class)->grant(
        $this->admin, $this->planner->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung',
    );
});

it('gives Wehrführung plan rights only at assigned locations and filters plan creation options', function () {
    expect($this->planner->canUseModule('schichtplan'))->toBeTrue()
        ->and($this->planner->canManageSchichtplan())->toBeFalse()
        ->and($this->planner->canManageSchichtplanAt($this->north->id))->toBeTrue()
        ->and($this->planner->canManageSchichtplanAt($this->south->id))->toBeFalse()
        ->and($this->planner->canManageAnySchichtplan())->toBeTrue()
        ->and($this->planner->standardRoleLocationIds('schichtplan', ['planer']))->toBe([$this->north->id])
        ->and(StandardRoleAssignment::where('user_id', $this->planner->id)->value('profile'))->toBe('wehrfuehrung');

    $this->actingAs($this->planner)->get(route('schichtplan.create'))->assertOk();
    Livewire::actingAs($this->planner)->test(PlanCreate::class)
        ->assertSet('locationId', $this->north->id)
        ->assertSee('Wache Nord')
        ->assertDontSee('Wache Süd');

    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $plan = app(PlanService::class)->create([
        'title' => 'Nord Gerätehausdienst',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'shift_minutes' => 60,
        'ext_location_id' => $this->north->id,
    ], $this->planner);
    app(PlanService::class)->addStation($plan, 'Kasse');
    $plan->update(['status' => Plan::PUBLISHED]);
    $this->actingAs($this->planner)->get(route('schichtplan.index'))->assertOk()->assertSee('Wache Nord');
    $this->actingAs($this->planner)->get(route('schichtplan.show', $plan))->assertOk()->assertSee('Standort: Wache Nord');
    $this->actingAs($this->planner)->get(route('schichtplan.print', $plan))->assertOk()->assertSee('Standort: Wache Nord');
    $this->actingAs($this->planner)->get(route('schichtplan.csv', $plan))->assertOk()->assertSee('Wache Nord');
    expect((string) (new PlanNotification($plan))->toMail($this->planner)->render())->toContain('Wache Nord');

    $this->actingAs($this->admin);
    $otherPlan = app(PlanService::class)->create([
        'title' => 'Süd Gerätehausdienst',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'shift_minutes' => 60,
        'ext_location_id' => $this->south->id,
    ], $this->admin);
    $this->actingAs($this->planner);

    expect($plan->ext_location_id)->toBe($this->north->id)
        ->and(app(PlanLocationAccess::class)->canManage($this->planner, $plan->ext_location_id))->toBeTrue()
        ->and(app(PlanLocationAccess::class)->canManage($this->planner, $otherPlan->ext_location_id))->toBeFalse();

    $this->actingAs($this->planner)->get(route('schichtplan.edit', $otherPlan))->assertNotFound();
    expect(fn () => app(PlanService::class)->create([
        'title' => 'Fremder Plan',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'shift_minutes' => 60,
        'ext_location_id' => $this->south->id,
    ], $this->planner))->toThrow(ValidationException::class);
});

it('limits signup audiences and participants to the plan Standort', function () {
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $plan = app(PlanService::class)->create([
        'title' => 'Nord Festdienst',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'shift_minutes' => 60,
        'ext_location_id' => $this->north->id,
        'signup' => 'self',
    ], $this->planner);
    $station = app(PlanService::class)->addStation($plan, 'Kasse');
    $plan->update(['status' => Plan::PUBLISHED]);

    $this->otherSiteMember->moduleAccess()->create(['module' => 'schichtplan']);
    expect(SignupService::mayJoin($this->otherSiteMember, $plan))->toBeFalse()
        ->and(fn () => app(PlanService::class)->addParticipants($plan, [$this->otherSiteMember->id]))
        ->toThrow(ValidationException::class);

    expect(fn () => app(SignupService::class)->signUp(
        $this->otherSiteMember,
        $plan,
        $plan->slots()->first(),
        $station,
    ))->toThrow(ValidationException::class);

    $foreignGroup = $this->south->groups()->create(['name' => 'Jugendfeuerwehr']);
    expect(fn () => app(PlanService::class)->addGroup($plan, $foreignGroup))->toThrow(ValidationException::class);
});
