<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Schichtplan\src\Livewire\PlanTimes;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;

beforeEach(function () {
    Module::where('key', 'schichtplan')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->plans = app(PlanService::class);
});

function schichtplanUsers(int $count): Collection
{
    return User::factory()->count($count)->create(['phone' => null])
        ->each(fn (User $u) => $u->moduleAccess()->create(['module' => 'schichtplan']));
}

/** A plan 14:00 + $hours on 12.06.2027 (Berlin) with the given stations [name => capacity] */
function schichtplan(array $stations, int $hours, int $minutes = 60, int $maxConsecutive = 2): Plan
{
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin')->utc();
    $plan = app(PlanService::class)->create([
        'title' => 'Dorffest – Pommesstand', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours($hours),
        'shift_minutes' => $minutes, 'max_consecutive' => $maxConsecutive,
    ], null);

    foreach ($stations as $name => $capacity) {
        app(PlanService::class)->addStation($plan, $name, $capacity);
    }

    return $plan;
}

// ─── blocks ──────────────────────────────────────────────────────────────────

test('the blocks follow start, end and shift length, the last one may be shorter', function () {
    $plan = schichtplan([], 8);
    expect($plan->slots()->count())->toBe(8)
        ->and($plan->slots()->first()->label())->toBe('14:00–15:00');

    $this->plans->update($plan, ['starts_at' => $plan->starts_at, 'ends_at' => $plan->ends_at, 'shift_minutes' => 90]);
    expect($plan->slots()->count())->toBe(6)
        ->and($plan->slots()->get()->last()->label())->toBe('21:30–22:00');
});

test('a plan can go past midnight and impossible times are refused', function () {
    [$start, $end] = PlanTimes::fromInputs('2027-06-12', '18:00', '02:00');
    expect($start->diffInHours($end))->toBe(8.0);

    expect(fn () => $this->plans->create(['title' => 'X', 'starts_at' => $end, 'ends_at' => $start, 'shift_minutes' => 60], null))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->plans->create(['title' => 'X', 'starts_at' => $start, 'ends_at' => $end, 'shift_minutes' => 5], null))
        ->toThrow(ValidationException::class);
});

// ─── distribution ────────────────────────────────────────────────────────────

test('everyone does every station once before anything repeats, with an even number of shifts', function (int $people, array $stations, int $hours) {
    $plan = schichtplan($stations, $hours);
    $this->plans->addParticipants($plan, schichtplanUsers($people)->pluck('id'));

    foreach ([1, 2, 3] as $seed) {
        app(ShiftPlanner::class)->fill($plan, $seed);
        $stats = $this->plans->stats($plan);
        $stationCount = count($stations);

        expect($stats['gaps'])->toBeEmpty()
            ->and($stats['max'] - $stats['min'])->toBeLessThanOrEqual(1);

        foreach ($stats['people'] as $person) {
            // Someone with n shifts has min(n, stations) different stations
            $distinct = $stationCount - $person['missing']->count();
            expect($distinct)->toBe(min($person['total'], $stationCount));
        }
    }
})->with([
    'JF Pommesstand' => [8, ['Fritteuse' => 2, 'Ausgabe' => 1, 'Kasse' => 1], 8],
    'Grillstand Aktive' => [6, ['Grill' => 1, 'Theke' => 1, 'Getränke' => 1], 6],
    'Großes Fest' => [12, ['A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1], 10],
]);

test('nobody stands at two stations in the same block', function () {
    $plan = schichtplan(['Grill' => 2, 'Kasse' => 2], 4);
    $this->plans->addParticipants($plan, schichtplanUsers(4)->pluck('id'));

    app(ShiftPlanner::class)->fill($plan, 1);

    expect(Assignment::where('plan_id', $plan->id)->count())->toBe(16)
        ->and(Assignment::where('plan_id', $plan->id)->get()->groupBy('slot_id')->every(fn ($a) => $a->pluck('user_id')->unique()->count() === $a->count()))->toBeTrue();
});

test('too few people leave visible gaps instead of double shifts', function () {
    $plan = schichtplan(['Fritteuse' => 2, 'Kasse' => 1], 2);
    $this->plans->addParticipants($plan, schichtplanUsers(2)->pluck('id'));

    app(ShiftPlanner::class)->fill($plan, 1);
    $stats = $this->plans->stats($plan);

    expect($stats['gaps']->sum('missing'))->toBe(2)
        ->and($stats['people']->pluck('total')->all())->toBe([2, 2]);
});

test('availability is respected', function () {
    $plan = schichtplan(['Kasse' => 1], 6);
    [$early, $late] = schichtplanUsers(2)->all();
    $this->plans->addParticipants($plan, [$early->id, $late->id]);
    $this->plans->setAvailability($plan, $early->id, null, Carbon::parse('2027-06-12 17:00', 'Europe/Berlin'));

    app(ShiftPlanner::class)->fill($plan, 1);

    $earlyShifts = Assignment::where('user_id', $early->id)->with('slot')->get();
    expect($earlyShifts)->not->toBeEmpty()
        ->and($earlyShifts->every(fn ($a) => $a->slot->ends_at->lte(Carbon::parse('2027-06-12 17:00', 'Europe/Berlin'))))->toBeTrue();
});

test('breaks are kept when there are enough people', function () {
    $plan = schichtplan(['Kasse' => 1], 8, maxConsecutive: 2);
    $this->plans->addParticipants($plan, schichtplanUsers(3)->pluck('id'));

    app(ShiftPlanner::class)->fill($plan, 1);

    $slots = $plan->slots()->pluck('id')->all();
    $byUser = Assignment::where('plan_id', $plan->id)->get()->groupBy('user_id');
    foreach ($byUser as $assignments) {
        $positions = $assignments->map(fn ($a) => array_search($a->slot_id, $slots))->sort()->values()->all();
        $run = 1;
        for ($i = 1; $i < count($positions); $i++) {
            $run = $positions[$i] === $positions[$i - 1] + 1 ? $run + 1 : 1;
            expect($run)->toBeLessThanOrEqual(2);
        }
    }
});

test('hand-set places survive a redistribution', function () {
    $plan = schichtplan(['Grill' => 1, 'Kasse' => 1], 3);
    $users = schichtplanUsers(3);
    $this->plans->addParticipants($plan, $users->pluck('id'));
    $slot = $plan->slots()->first();
    $grill = $plan->stations()->where('name', 'Grill')->first();

    $this->plans->setSeat($plan, $slot, $grill, null, $users[0]->id);
    app(ShiftPlanner::class)->fill($plan, 7);

    expect(Assignment::where('slot_id', $slot->id)->where('station_id', $grill->id)->sole())
        ->user_id->toBe($users[0]->id)
        ->locked->toBeTrue();
});

// ─── manual changes ──────────────────────────────────────────────────────────

test('picking someone who works elsewhere in the block swaps the two', function () {
    $plan = schichtplan(['Grill' => 1, 'Kasse' => 1], 1);
    [$anna, $ben] = schichtplanUsers(2)->all();
    $this->plans->addParticipants($plan, [$anna->id, $ben->id]);
    $slot = $plan->slots()->first();
    [$grill, $kasse] = $plan->stations()->get()->all();
    $this->plans->setSeat($plan, $slot, $grill, null, $anna->id);
    $this->plans->setSeat($plan, $slot, $kasse, null, $ben->id);

    $this->plans->setSeat($plan, $slot, $grill, $anna->id, $ben->id);

    expect(Assignment::where('station_id', $grill->id)->sole()->user_id)->toBe($ben->id)
        ->and(Assignment::where('station_id', $kasse->id)->sole()->user_id)->toBe($anna->id);
});

test('a full station takes nobody else and strangers are refused', function () {
    $plan = schichtplan(['Kasse' => 1], 1);
    [$anna, $ben] = schichtplanUsers(2)->all();
    $this->plans->addParticipants($plan, [$anna->id, $ben->id]);
    $slot = $plan->slots()->first();
    $kasse = $plan->stations()->first();
    $this->plans->setSeat($plan, $slot, $kasse, null, $anna->id);

    expect(fn () => $this->plans->setSeat($plan, $slot, $kasse, null, $ben->id))->toThrow(ValidationException::class);
    expect(fn () => $this->plans->setSeat($plan, $slot, $kasse, $anna->id, User::factory()->create()->id))->toThrow(ValidationException::class);
});

test('fewer places at a station drop the surplus, hand-set ones last', function () {
    $plan = schichtplan(['Fritteuse' => 2], 1);
    [$anna, $ben] = schichtplanUsers(2)->all();
    $this->plans->addParticipants($plan, [$anna->id, $ben->id]);
    $slot = $plan->slots()->first();
    $station = $plan->stations()->first();
    app(ShiftPlanner::class)->fill($plan, 1);
    Assignment::where('user_id', $anna->id)->update(['locked' => true]);

    $this->plans->updateStation($station, 'Fritteuse', 1);

    expect(Assignment::where('slot_id', $slot->id)->sole()->user_id)->toBe($anna->id);
});

test('removing a participant or narrowing availability removes their shifts', function () {
    $plan = schichtplan(['Kasse' => 1], 4);
    [$anna, $ben] = schichtplanUsers(2)->all();
    $this->plans->addParticipants($plan, [$anna->id, $ben->id]);
    app(ShiftPlanner::class)->fill($plan, 1);

    $this->plans->setAvailability($plan, $anna->id, Carbon::parse('2027-06-12 17:00', 'Europe/Berlin'), null);
    expect(Assignment::where('user_id', $anna->id)->with('slot')->get()->every(fn ($a) => $a->slot->starts_at->gte(Carbon::parse('2027-06-12 17:00', 'Europe/Berlin'))))->toBeTrue();

    $this->plans->removeParticipant($plan, $ben->id);
    expect(Assignment::where('user_id', $ben->id)->exists())->toBeFalse()
        ->and($plan->participants()->count())->toBe(1);
});

test('only Kameraden with access to the module can take part', function () {
    $plan = schichtplan(['Kasse' => 1], 1);
    $outsider = User::factory()->create();
    $group = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $members = schichtplanUsers(3)->each(fn (User $u) => $u->groups()->attach($group));
    $outsider->groups()->attach($group);

    expect($this->plans->addGroup($plan, $group))->toBe(3)
        ->and($plan->participants()->pluck('user_id')->sort()->values()->all())->toBe($members->pluck('id')->sort()->values()->all());
});

test('templates keep the stations for the next fair', function () {
    $plan = schichtplan(['Fritteuse' => 2, 'Kasse' => 1], 2);
    $template = $this->plans->saveTemplate($plan, 'Pommesstand JF');
    $next = schichtplan([], 2);

    $this->plans->applyTemplate($next, $template);

    expect($next->stations()->get()->map(fn ($s) => [$s->name, $s->capacity])->all())->toBe([['Fritteuse', 2], ['Kasse', 1]]);
});
