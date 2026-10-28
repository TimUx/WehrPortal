<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LeadershipAssignments;
use Extensions\Locations\Services\LeadershipProjections;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Europe/Berlin'));
    config(['locations.enabled' => true, 'locations.leadership_enabled' => true]);
    Module::whereIn('key', ['einsatzbericht', 'fahrzeuge', 'ausruestung', 'kleiderkammer', 'personalverwaltung'])
        ->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->reader = User::factory()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'short_name' => 'Nord', 'slug' => 'projection-north']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'projection-south']);
    $this->projections = app(LeadershipProjections::class);
    $this->grantProjection = function (string $capability) {
        $profile = array_key_first(array_filter(LeadershipAssignments::PROFILES,
            fn (array $capabilities) => in_array($capability, $capabilities, true)));
        $capabilities = [$capability];
        $existing = DB::table('x_leadership_assignments')->where('user_id', $this->reader->id)
            ->where('location_id', $this->north->id)->where('profile', $profile)->whereNull('revoked_at')->first();
        if ($existing !== null) {
            $capabilities = array_values(array_unique([...json_decode($existing->capabilities, true), $capability]));
            app(LeadershipAssignments::class)->revoke($this->admin, $existing->id, $this->north->id, 'Testauftrag erweitert');
        }

        return app(LeadershipAssignments::class)->grant($this->admin, $this->reader->id, $this->north->id,
            $profile, $capabilities, 'Begrenzte Übersicht', now()->addDay()->toIso8601String());
    };
    $this->vehicle = fn (?int $location) => DB::table('vehicles')->insertGetId([
        'name' => 'Fahrzeug TEST', 'is_active' => true, 'ext_location_id' => $location,
    ]);
    $this->equipment = fn (?int $location, string $code, string $status = 'available', ?string $due = null) => DB::table('ar_equipment')->insertGetId([
        'code' => $code, 'name' => 'Gerät TEST', 'ext_location_id' => $location, 'status' => $status,
        'next_inspection_at' => $due, 'notes' => 'Vertraulicher Freitext',
    ]);
});

it('returns only allowlisted fields for each bounded projection', function (string $capability) {
    ($this->grantProjection)($capability);
    $year = in_array($capability, ['incident_totals', 'fleet_inspections', 'equipment_inspections'], true) ? 2026 : null;
    $before = DB::table('activity_logs')->count();
    $result = $this->projections->read($this->reader, $this->north->id, $capability, $year);
    expect(array_keys($result))->toBe(LeadershipAssignments::PROJECTION_FIELDS[$capability])
        ->and(DB::table('activity_logs')->count())->toBe($before);
})->with(array_keys(LeadershipAssignments::CAPABILITIES));

it('requires a fresh exact grant on every projection read', function (string $change) {
    $grant = ($this->grantProjection)('personnel_summary');
    $this->projections->read($this->reader, $this->north->id, 'personnel_summary');
    match ($change) {
        'user' => DB::table('users')->where('id', $this->reader->id)->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'personalverwaltung')->update(['is_active' => false]),
        'location' => DB::table('x_locations')->where('id', $this->north->id)->update(['is_active' => false]),
        'revoked' => app(LeadershipAssignments::class)->revoke($this->admin, $grant->id, $this->north->id, 'Beendet'),
        'expired' => $this->travel(2)->days(),
        'leadership' => config(['locations.leadership_enabled' => false]),
        'locations' => config(['locations.enabled' => false]),
        'wrong location' => $this->north = $this->south,
    };
    expect(fn () => $this->projections->read($this->reader, $this->north->id, 'personnel_summary'))->toThrow(HttpException::class);
})->with(['user', 'module', 'location', 'revoked', 'expired', 'leadership', 'locations', 'wrong location']);

it('rejects ungranted raw and retired capabilities even for global administrators', function (string $capability) {
    foreach ([$this->reader, $this->admin] as $actor) {
        expect(fn () => $this->projections->read($actor, $this->north->id, $capability))->toThrow(HttpException::class);
    }
})->with(['personnel', 'health', 'bank', 'personnel_file', 'raw', 'announcement_configuration', '*']);

it('exposes only one yearly incident total using Berlin year boundaries and counted parent reports', function () {
    ($this->grantProjection)('incident_totals');
    $insert = fn (string $number, ?int $location, string $time, string $status = 'released', ?int $parent = null) => DB::table('eb_reports')->insertGetId([
        'number' => $number, 'ext_location_id' => $location, 'alarmed_at' => $time, 'status' => $status, 'parent_id' => $parent,
        'address' => 'Vertrauliche Adresse', 'situation' => 'Vertraulicher Bericht',
    ]);
    $parent = $insert('P6-1', $this->north->id, '2025-12-31 23:00:00');
    $insert('P6-2', $this->north->id, '2026-12-31 22:59:59');
    $insert('P6-3', $this->north->id, '2025-12-31 22:59:59');
    $insert('P6-4', $this->north->id, '2026-12-31 23:00:00');
    $insert('P6-5', $this->south->id, '2026-01-01 12:00:00');
    $insert('P6-6', null, '2026-01-01 12:00:00');
    $insert('P6-7', $this->north->id, '2026-01-01 12:00:00', 'closed');
    $insert('P6-8', $this->north->id, '2026-01-01 12:00:00', 'draft');
    $insert('P6-9', $this->north->id, '2026-01-01 12:00:00', 'released', $parent);
    expect($this->projections->read($this->reader, $this->north->id, 'incident_totals', 2026))->toBe(['year' => 2026, 'incident_count' => 2]);
    Setting::set('einsatzbericht.released_only', '0');
    expect($this->projections->read($this->reader, $this->north->id, 'incident_totals', 2026))->toBe(['year' => 2026, 'incident_count' => 3]);
});

it('counts active primary or explicitly associated personnel once with exact local department totals only', function () {
    ($this->grantProjection)('personnel_summary');
    $member = User::factory()->create(['ext_location_id' => $this->north->id, 'name' => 'Hanna Hydrant', 'notes' => 'Vertrauliche Personalakte']);
    $secondary = User::factory()->create(['ext_location_id' => $this->south->id]);
    User::factory()->create(['ext_location_id' => null]);
    User::factory()->create(['ext_location_id' => $this->north->id, 'is_active' => false]);
    DB::table('x_location_user')->upsert([
        ['user_id' => $member->id, 'location_id' => $this->north->id, 'role' => 'member'],
        ['user_id' => $secondary->id, 'location_id' => $this->north->id, 'role' => 'member'],
    ], ['location_id', 'user_id'], ['role']);
    $local = $this->north->groups()->where('name', 'Einsatzabteilung')->value('id');
    $foreign = DB::table('groups')->insertGetId(['name' => 'Fremde Abteilung', 'ext_location_id' => $this->south->id]);
    foreach ([$local, $foreign] as $id) {
        DB::table('group_user')->insert(['group_id' => $id, 'user_id' => $member->id]);
    }
    $departmentCounts = $this->north->groups()->orderBy('id')->get()->map(fn (Group $group) => [
        'department_id' => $group->id,
        'member_count' => $group->id === $local ? 1 : 0,
    ])->all();
    expect($this->projections->read($this->reader, $this->north->id, 'personnel_summary'))->toBe([
        'active_member_count' => 2, 'department_counts' => $departmentCounts,
    ]);
});

it('keeps the core location overview separate from personnel and module entitlements', function () {
    ($this->grantProjection)('location_overview');
    Module::where('key', 'personalverwaltung')->update(['is_active' => false]);
    expect($this->projections->read($this->reader, $this->north->id, 'location_overview'))
        ->toBe(['location_name' => 'Wache Nord', 'short_name' => 'Nord'])
        ->and(app(LeadershipAssignments::class)->allows($this->reader, $this->north->id, 'personalverwaltung', 'read', 'approved_location_overview'))->toBeFalse();
});

it('keeps projections disabled even for global administrators until the explicit opt-in', function () {
    config(['locations.leadership_enabled' => false]);
    expect(app(LeadershipAssignments::class)->allows($this->admin, null, 'unknown', 'manage', 'raw'))->toBeTrue();
    expect(fn () => $this->projections->read($this->admin, $this->north->id, 'personnel_summary'))->toThrow(HttpException::class);
});

it('fails closed instead of emitting unbounded department lists', function () {
    ($this->grantProjection)('personnel_summary');
    for ($index = 0; $index < 101; $index++) {
        DB::table('groups')->insert(['name' => 'Abteilung TEST '.$index, 'ext_location_id' => $this->north->id]);
    }
    expect(fn () => $this->projections->read($this->reader, $this->north->id, 'personnel_summary'))->toThrow(ValidationException::class);
});

it('counts only local asset statuses using fixed buckets without disclosing raw notes or identifiers', function () {
    ($this->grantProjection)('fleet_summary');
    ($this->grantProjection)('equipment_summary');
    foreach ([$this->north->id, $this->south->id, null] as $location) {
        $vehicle = ($this->vehicle)($location);
        DB::table('fv_profiles')->insert(['vehicle_id' => $vehicle, 'status' => 'limited', 'notes' => 'Vertraulich']);
        ($this->equipment)($location, 'P6-E-'.($location ?? 'NULL'), 'locked');
    }
    ($this->equipment)($this->north->id, 'P6-UNKNOWN', 'Vertraulich');
    $fleet = $this->projections->read($this->reader, $this->north->id, 'fleet_summary');
    $equipment = $this->projections->read($this->reader, $this->north->id, 'equipment_summary');
    expect($fleet['vehicle_count'])->toBe(1)->and($fleet['status_counts']['limited'])->toBe(1)
        ->and($equipment['equipment_count'])->toBe(2)->and($equipment['status_counts']['locked'])->toBe(1)
        ->and($equipment['status_counts']['unknown'])->toBe(1)
        ->and(json_encode([$fleet, $equipment]))->not->toContain('Vertraulich', 'P6-E');
});

it('summarizes local active deadlines in fixed Berlin date buckets', function () {
    ($this->grantProjection)('fleet_deadlines');
    ($this->grantProjection)('equipment_deadlines');
    $dates = [now()->subDay()->toDateString(), now()->toDateString(), now()->addDays(30)->toDateString(), now()->addDays(31)->toDateString()];
    foreach ([$this->north->id, $this->south->id, null] as $location) {
        $vehicle = ($this->vehicle)($location);
        foreach ($dates as $index => $date) {
            DB::table('fv_deadlines')->insert(['vehicle_id' => $vehicle, 'title' => 'Vertrauliche Prüfung', 'due_on' => $date]);
            ($this->equipment)($location, 'P6-D-'.($location ?? 'NULL').'-'.$index, 'available', $date);
        }
        DB::table('fv_deadlines')->insert(['vehicle_id' => $vehicle, 'title' => 'Nicht aktiv', 'due_on' => $dates[0], 'is_active' => false]);
    }
    expect($this->projections->read($this->reader, $this->north->id, 'fleet_deadlines'))
        ->toBe(['overdue' => 1, 'due_within_30_days' => 2, 'later' => 1, 'undated' => 0])
        ->and($this->projections->read($this->reader, $this->north->id, 'equipment_deadlines'))
        ->toBe(['overdue' => 1, 'due_within_30_days' => 2, 'later' => 1, 'undated' => 0]);
});

it('counts annual inspections without inspector identities reports or attachments', function () {
    ($this->grantProjection)('fleet_inspections');
    ($this->grantProjection)('equipment_inspections');
    foreach ([$this->north->id, $this->south->id, null] as $location) {
        $vehicle = ($this->vehicle)($location);
        $equipment = ($this->equipment)($location, 'P6-I-'.($location ?? 'NULL'));
        foreach (['2025-12-31', '2026-01-01', '2026-12-31', '2027-01-01'] as $date) {
            DB::table('fv_records')->insert(['vehicle_id' => $vehicle, 'kind' => 'inspection', 'title' => 'Vertraulich',
                'done_on' => $date, 'result' => 'passed', 'notes' => 'Vertraulicher Prüfbericht']);
            DB::table('ar_inspections')->insert(['equipment_id' => $equipment, 'inspected_at' => $date, 'passed' => false, 'notes' => 'Vertraulich']);
        }
        DB::table('fv_records')->insert(['vehicle_id' => $vehicle, 'kind' => 'repair', 'title' => 'Keine Prüfung', 'done_on' => '2026-01-01', 'result' => 'passed']);
    }
    expect($this->projections->read($this->reader, $this->north->id, 'fleet_inspections', 2026))
        ->toBe(['year' => 2026, 'passed' => 2, 'limited' => 0, 'failed' => 0, 'unknown' => 0])
        ->and($this->projections->read($this->reader, $this->north->id, 'equipment_inspections', 2026))
        ->toBe(['year' => 2026, 'passed' => 0, 'failed' => 2]);
});

it('counts exact clothing stock and nondeleted tracked items without personal issuance history', function () {
    ($this->grantProjection)('stock_summary');
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe', 'is_tracked' => true]);
    foreach ([$this->north->id, $this->south->id, null] as $location) {
        DB::table('kk_stock')->insert(['item_type_id' => $type, 'ext_location_id' => $location, 'quantity' => 5]);
        DB::table('kk_items')->insert(['item_type_id' => $type, 'ext_location_id' => $location, 'code' => 'P6-K-'.($location ?? 'NULL')]);
    }
    DB::table('kk_items')->insert(['item_type_id' => $type, 'ext_location_id' => $this->north->id, 'code' => 'P6-K-DELETED', 'deleted_at' => now()]);
    expect($this->projections->read($this->reader, $this->north->id, 'stock_summary'))->toBe(['stock_quantity' => 5, 'tracked_item_count' => 1]);
});

it('requires an explicit bounded year only for annual projections', function (string $capability, ?int $year) {
    ($this->grantProjection)($capability);
    expect(fn () => $this->projections->read($this->reader, $this->north->id, $capability, $year))->toThrow(ValidationException::class);
})->with([['incident_totals', null], ['fleet_inspections', null], ['equipment_inspections', 1899],
    ['incident_totals', 2027], ['personnel_summary', 2026]]);

it('serves protected JSON summaries through the existing CLI without granting editing or export access', function () {
    ($this->grantProjection)('incident_totals');
    $options = ['operation' => 'summary', '--by' => (string) $this->reader->id,
        '--location' => (string) $this->north->id, '--capability' => ['incident_totals'], '--year' => '2026'];
    $this->artisan('locations:leadership', $options)->expectsOutput('{"year":2026,"incident_count":0}')->assertSuccessful();
    foreach ([
        ['--location' => (string) $this->south->id], ['--capability' => ['personnel_summary']],
        ['--capability' => ['incident_totals', 'fleet_summary']], ['--capability' => []],
        ['--year' => '2026-01'], ['--year' => '2027'], ['--reason' => 'Keine Änderung'],
    ] as $invalid) {
        $this->artisan('locations:leadership', array_replace($options, $invalid))->assertFailed();
    }
    expect(DB::table('module_user')->where('user_id', $this->reader->id)->exists())->toBeFalse();
});
