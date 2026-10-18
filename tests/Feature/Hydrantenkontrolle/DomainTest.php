<?php

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Hydrantenkontrolle\Support\Checklist;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Module::where('key', 'hydrantenkontrolle')->firstOrFail()->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->station = Location::create(['name' => 'Wache Süd', 'slug' => 'hc-sued']);
    $this->manager = User::factory()->create(['name' => 'Hanna Hydrant', 'ext_location_id' => $this->station->id]);
    $this->manager->moduleAccess()->create(['module' => 'hydrantenkontrolle', 'role' => 'hydrantenwart']);
    $this->actingAs($this->manager);
    $this->service = app(InspectionService::class);
});

it('limits management to active authorized module users even for admins', function () {
    $member = User::factory()->create();
    $member->moduleAccess()->create(['module' => 'hydrantenkontrolle']);
    $inactive = User::factory()->create(['is_active' => false, 'is_admin' => true]);
    $admin = User::factory()->create(['is_admin' => true]);
    expect(InspectionService::canManage($this->manager))->toBeTrue()
        ->and(InspectionService::canManage($member))->toBeFalse()
        ->and(InspectionService::canManage($inactive))->toBeFalse()
        ->and(InspectionService::canManage($admin))->toBeTrue();
    expect(fn () => $this->service->saveHydrant($member, ['number' => '1', 'street' => 'Musterweg']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->openYear($member, 2026))->toThrow(AuthorizationException::class);
    $hydrant = $this->service->saveHydrant($this->manager, ['number' => '1', 'street' => 'Musterweg']);
    $inspection = $this->service->openYear($this->manager, 2026)->inspections()->sole();
    expect(fn () => $this->service->saveInspection($member, $inspection, ['notes' => 'Nein'], 1))
        ->toThrow(AuthorizationException::class);
    Module::where('key', 'hydrantenkontrolle')->firstOrFail()->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    expect(InspectionService::canManage($admin))->toBeFalse()
        ->and($hydrant->number)->toBe('1');
});

it('trims hydrant fields and supports an address description or coordinate pair', function () {
    $hydrant = $this->service->saveHydrant($this->manager, [
        'number' => '  H-1 ', 'street' => '  Musterweg ', 'house_number' => ' 12a ', 'location_description' => ' ',
    ]);
    expect($hydrant->number)->toBe('H-1')->and($hydrant->street)->toBe('Musterweg')
        ->and($hydrant->house_number)->toBe('12a')->and($hydrant->location_description)->toBeNull()
        ->and($hydrant->version)->toBe(1)->and($hydrant->active)->toBeTrue()
        ->and($hydrant->ext_location_id)->toBe($this->station->id);
    $this->service->saveHydrant($this->manager, ['number' => 'H-2', 'location_description' => 'Am Übungsplatz']);
    $coords = $this->service->saveHydrant($this->manager, ['number' => 'H-3', 'latitude' => '0', 'longitude' => '0']);
    expect((float) $coords->latitude)->toBe(0.0)->and(Hydrant::count())->toBe(3);
});

it('rejects invalid hydrant data', function (array $data) {
    expect(fn () => $this->service->saveHydrant($this->manager, $data))->toThrow(ValidationException::class);
    expect(Hydrant::count())->toBe(0);
})->with([
    'missing number' => [['street' => 'Musterweg']],
    'blank number' => [['number' => ' ', 'street' => 'Musterweg']],
    'no position' => [['number' => 'H-1']],
    'house number alone' => [['number' => 'H-1', 'house_number' => '4']],
    'missing longitude' => [['number' => 'H-1', 'latitude' => 50]],
    'missing latitude' => [['number' => 'H-1', 'longitude' => 8]],
    'invalid latitude' => [['number' => 'H-1', 'latitude' => 91, 'longitude' => 8]],
    'invalid longitude' => [['number' => 'H-1', 'latitude' => 50, 'longitude' => -181]],
    'invalid active' => [['number' => 'H-1', 'street' => 'Musterweg', 'active' => 'yes']],
]);

it('uses optimistic locking for hydrants and translates duplicate numbers', function () {
    $hydrant = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $changed = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Neuer Weg', 'active' => false], $hydrant, 1);
    expect($changed->version)->toBe(2)->and($changed->active)->toBeFalse();
    expect(fn () => $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Alt'], $hydrant, 1))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Alt'], $hydrant))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Alt']))
        ->toThrow(ValidationException::class);
    expect($hydrant->fresh()->street)->toBe('Neuer Weg');
});

it('uses German field names in validator messages', function () {
    try {
        $this->service->saveHydrant($this->manager, ['street' => 'Musterweg']);
        $this->fail('A missing number must fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['number'][0])->toContain('Nummer')->not->toContain('number');
    }
    $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $inspection = $this->service->openYear($this->manager, 2026)->inspections()->sole();
    try {
        $this->service->saveInspection($this->manager, $inspection, ['checked_at' => 'invalid', 'is_ok' => true], 1);
        $this->fail('An invalid date must fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['checked_at'][0])->toContain('Prüfdatum')->not->toContain('checked_at');
    }
});

it('opens independent years and only appends newly active hydrants without changing archived snapshots', function () {
    $hydrant = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg', 'house_number' => '4']);
    $inactive = $this->service->saveHydrant($this->manager, ['number' => 'H-2', 'street' => 'Musterweg', 'active' => false]);
    $old = $this->service->openYear($this->manager, 2026);
    $inspection = $old->inspections()->sole();
    $snapshot = $inspection->snapshot;
    $inspection = $this->service->saveInspection($this->manager, $inspection, ['checked_at' => '2026-10-10', 'is_ok' => true, 'notes' => 'Erledigt'], 1);
    $this->service->saveHydrant($this->manager, ['number' => 'H-99', 'street' => 'Neuer Weg', 'active' => false], $hydrant, 1);
    $this->service->saveHydrant($this->manager, ['number' => 'H-2', 'street' => 'Musterweg', 'active' => true], $inactive, 1);
    $this->service->openYear($this->manager, 2026);
    $this->service->openYear($this->manager, 2026);
    expect($old->inspections()->count())->toBe(2)
        ->and($inspection->fresh()->snapshot)->toBe($snapshot)
        ->and($inspection->fresh()->status())->toBe('ok')
        ->and($inspection->fresh()->notes)->toBe('Erledigt')
        ->and($inspection->fresh()->version)->toBe(2);
    $next = $this->service->openYear($this->manager, 2027);
    expect($next->inspections()->sole()->hydrant_id)->toBe($inactive->id)
        ->and($next->inspections()->sole()->status())->toBe('unchecked')
        ->and(InspectionYear::count())->toBe(2);
    expect(ActivityLog::where('subject_type', InspectionYear::class)->where('event', 'created')->count())->toBe(2);
});

it('rejects impossible control years', function (int $year) {
    expect(fn () => $this->service->openYear($this->manager, $year))->toThrow(ValidationException::class);
    expect(InspectionYear::count())->toBe(0);
})->with([0, 1899, 10000]);

it('validates inspection results and dates', function (array $data) {
    $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $inspection = $this->service->openYear($this->manager, 2026)->inspections()->sole();
    expect(fn () => $this->service->saveInspection($this->manager, $inspection, $data, 1))->toThrow(ValidationException::class);
    expect($inspection->fresh()->version)->toBe(1);
})->with([
    'ok without date' => [['is_ok' => true]],
    'defects without date' => [['defects' => ['cover_stuck']]],
    'date without result' => [['checked_at' => '2026-10-10']],
    'wrong year' => [['checked_at' => '2027-10-10', 'is_ok' => true]],
    'invalid date' => [['checked_at' => '2026-02-30', 'is_ok' => true]],
    'ok with defects' => [['checked_at' => '2026-10-10', 'is_ok' => true, 'defects' => ['cover_stuck']]],
    'unknown defect' => [['checked_at' => '2026-10-10', 'defects' => ['bogus']]],
    'duplicate defect' => [['checked_at' => '2026-10-10', 'defects' => ['cover_stuck', 'cover_stuck']]],
    'defect map' => [['checked_at' => '2026-10-10', 'defects' => ['cover_stuck' => true]]],
]);

it('records results inspector snapshots notes and meaningful activity changes without accepting identity input', function () {
    $hydrant = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $inspection = $this->service->openYear($this->manager, 2026)->inspections()->sole();
    $snapshot = $inspection->snapshot;
    $saved = $this->service->saveInspection($this->manager, $inspection, [
        'checked_at' => '2026-10-10', 'defects' => ['cover_stuck'], 'notes' => ' Deckel prüfen ',
        'hydrant_id' => 99999, 'inspection_year_id' => 99999, 'snapshot' => ['number' => 'FAKE'],
        'inspector_id' => 99999, 'inspector_name' => 'FAKE',
    ], 1);
    expect($saved->status())->toBe('defects')->and($saved->statusLabel())->toBe('Mängel')
        ->and($saved->inspector_id)->toBe($this->manager->id)->and($saved->inspector_name)->toBe('Hanna Hydrant')
        ->and($saved->notes)->toBe('Deckel prüfen')->and($saved->snapshot)->toBe($snapshot)
        ->and($saved->hydrant_id)->toBe($hydrant->id);
    $log = ActivityLog::where('subject_type', Inspection::class)->where('subject_id', $saved->id)->where('event', 'updated')->sole();
    expect($log->changes['defects'])->toBe([[], ['cover_stuck']])
        ->and($log->changes['notes'])->toBe([null, 'Deckel prüfen'])
        ->and($log->user_id)->toBe($this->manager->id);
    $ok = $this->service->saveInspection($this->manager, $saved, ['checked_at' => '2026-10-11', 'is_ok' => true], 2);
    expect($ok->status())->toBe('ok')->and($ok->statusLabel())->toBe('In Ordnung');
    $unchecked = $this->service->saveInspection($this->manager, $ok, ['notes' => 'Nächster Termin offen'], 3);
    expect($unchecked->status())->toBe('unchecked')->and($unchecked->statusLabel())->toBe('Ungeprüft')
        ->and($unchecked->checked_at)->toBeNull()->and($unchecked->notes)->toBe('Nächster Termin offen');
});

it('rejects a stale inspection writer and preserves the first result', function () {
    $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $inspection = $this->service->openYear($this->manager, 2026)->inspections()->sole();
    $this->service->saveInspection($this->manager, $inspection, ['checked_at' => '2026-10-10', 'is_ok' => true], 1);
    expect(fn () => $this->service->saveInspection($this->manager, $inspection, ['checked_at' => '2026-10-11', 'defects' => ['not_found']], 1))
        ->toThrow(ValidationException::class);
    expect($inspection->fresh()->status())->toBe('ok')->and($inspection->fresh()->version)->toBe(2);
});

it('prevents cross-location hydrant and inspection writes and location reassignment', function () {
    $south = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg']);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'hc-nord']);
    auth()->logout();
    $other = User::factory()->create(['ext_location_id' => $north->id]);
    $other->moduleAccess()->create(['module' => 'hydrantenkontrolle', 'role' => 'hydrantenwart']);
    $this->actingAs($other);
    $foreign = $this->service->saveHydrant($other, ['number' => 'H-1', 'street' => 'Nordweg']);
    $year = $this->service->openYear($other, 2026);
    $inspection = $year->inspections()->sole();
    $this->actingAs($this->manager);
    expect(fn () => $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Fremd'], $foreign, 1))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->service->saveInspection($this->manager, $inspection, ['notes' => 'Fremd'], 1))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'street' => 'Musterweg', 'ext_location_id' => $north->id], $south, 1))
        ->toThrow(ValidationException::class);
    expect($this->service->openYear($this->manager, 2026)->inspections()->sole()->hydrant_id)->toBe($south->id);
    expect(InspectionYear::withoutGlobalScopes()->count())->toBe(2);
});

it('enforces database uniqueness including null locations and keeps referenced history', function () {
    config(['locations.enabled' => false]);
    $insertHydrant = fn () => DB::table('x_hc_hydrants')->insertGetId(['number' => 'NULL-1', 'ext_location_id' => null]);
    $id = $insertHydrant();
    expect(fn () => DB::transaction($insertHydrant))->toThrow(QueryException::class);
    $yearId = DB::table('x_hc_years')->insertGetId(['year' => 2026, 'ext_location_id' => null]);
    expect(fn () => DB::transaction(fn () => DB::table('x_hc_years')->insert(['year' => 2026, 'ext_location_id' => null])))
        ->toThrow(QueryException::class);
    $row = ['inspection_year_id' => $yearId, 'hydrant_id' => $id, 'snapshot' => json_encode(['number' => 'NULL-1'])];
    DB::table('x_hc_inspections')->insert($row);
    expect(fn () => DB::transaction(fn () => DB::table('x_hc_inspections')->insert($row)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('x_hc_hydrants')->where('id', $id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('x_hc_years')->where('id', $yearId)->delete()))->toThrow(QueryException::class);
});

it('includes previously located active hydrants in the common year when Locations is disabled', function () {
    $south = $this->service->saveHydrant($this->manager, ['number' => 'S-1', 'street' => 'Musterweg']);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'hc-north-disabled']);
    $northId = DB::table('x_hc_hydrants')->insertGetId([
        'ext_location_id' => $north->id, 'number' => 'N-1', 'street' => 'Beispielweg', 'active' => true,
    ]);
    DB::table('x_hc_hydrants')->insert([
        'ext_location_id' => $north->id, 'number' => 'N-2', 'street' => 'Beispielweg', 'active' => false,
    ]);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    config(['locations.enabled' => false]);

    // Simulate a reboot without the Standort hooks installed by this test application's provider.
    Model::withoutEvents(function () use ($admin, $south, $northId) {
        $year = $this->service->openYear($admin, 2026);
        expect($year->ext_location_id)->toBeNull()
            ->and($year->inspections()->pluck('hydrant_id')->all())->toEqualCanonicalizing([$south->id, $northId]);
        $this->service->openYear($admin, 2026);
        expect($year->inspections()->count())->toBe(2);
    });
});

it('opens years idempotently without Locations and supports all ten checklist defects', function () {
    config(['locations.enabled' => false]);
    // The provider only installs Standort hooks at boot; suppress those already installed in this test app.
    Model::withoutEvents(function () {
        $hydrant = $this->service->saveHydrant($this->manager, ['number' => 'H-1', 'location_description' => 'Übungsplatz']);
        $first = $this->service->openYear($this->manager, 2026);
        $second = $this->service->openYear($this->manager, 2026);
        expect($first->id)->toBe($second->id)->and($first->ext_location_id)->toBeNull()
            ->and($hydrant->ext_location_id)->toBeNull()->and($first->inspections()->count())->toBe(1)
            ->and(Checklist::DEFECTS)->toHaveCount(10)->and(Checklist::OK)->toBe('Unterflurhydrant in Ordnung');
        expect(array_values(Checklist::DEFECTS))->toBe([
            'Unterflurhydrant nicht aufgefunden',
            'Unterflurhydrant außer Betrieb gesetzt',
            'Hydrantendeckel lässt sich nicht öffnen',
            'Steg vom Hydrantendeckel gebrochen',
            'Hydrantendeckel schließt nicht',
            'Fußventil undicht',
            'Hydrant entwässert nicht',
            'Bolzen von Deckel nicht vorhanden',
            'Standrohr/Schlüssel lassen sich nicht aufsetzen',
            'Hinweisschild unleserlich/falsch/fehlt',
        ]);
        $saved = $this->service->saveInspection($this->manager, $first->inspections()->sole(), [
            'checked_at' => '2026-10-10', 'defects' => array_keys(Checklist::DEFECTS),
        ], 1);
        expect($saved->defects)->toHaveCount(10);
    });
});
