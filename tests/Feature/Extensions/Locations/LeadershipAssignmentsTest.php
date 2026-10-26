<?php

use App\Enums\MemberStatus;
use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use App\Services\ModuleRegistry;
use Extensions\Locations\Console\LeadershipCommand;
use Extensions\Locations\Models\LeadershipAssignment;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LeadershipAssignments;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ProtectionManifest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    config(['locations.enabled' => true, 'locations.leadership_enabled' => true]);
    Module::whereIn('key', ['einsatzbericht', 'fahrzeuge', 'ausruestung', 'kleiderkammer', 'personalverwaltung', 'mitteilungen'])
        ->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->leadership = app(LeadershipAssignments::class);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->approver = User::factory()->create(['is_admin' => true]);
    $this->member = User::factory()->create();
    DB::table('x_location_user')->where('user_id', $this->member->id)->delete();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'leadership-north']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'leadership-south']);
    $this->grant = fn (string $profile = 'gbi', ?array $capabilities = null, ?string $expires = null, ?string $start = null) => $this->leadership->grant(
        $this->admin, $this->member->id, $this->north->id, $profile,
        $capabilities ?? LeadershipAssignments::PROFILES[$profile], 'Befristeter Testauftrag',
        $expires ?? now()->addDay()->toIso8601String(), $start,
    );
    // Seed pre-upgrade history without reopening the retired grant/approval API.
    $this->historicalMunicipalGrant = function (bool $approved = false) {
        DB::statement('ALTER TABLE x_leadership_assignments DISABLE TRIGGER x_leadership_history');
        try {
            return LeadershipAssignment::create([
                'user_id' => $this->member->id, 'location_id' => $this->north->id,
                'profile' => 'kommunaler_admin', 'capabilities' => ['announcement_configuration'],
                'granted_by' => $this->admin->id, 'granted_at' => now(),
                'reason' => 'Historischer Auftrag', 'valid_from' => now(), 'expires_at' => now()->addDay(),
                'approved_by' => $approved ? $this->approver->id : null,
                'approved_at' => $approved ? now() : null,
            ])->refresh();
        } finally {
            DB::statement('ALTER TABLE x_leadership_assignments ENABLE TRIGGER x_leadership_history');
        }
    };
});

dataset('leadership profiles', [
    'mayor' => ['buergermeister', ['incident_totals']],
    'municipality' => ['kommune', ['incident_totals']],
    'fire authority' => ['brandschutzamt', ['fleet_deadlines', 'equipment_deadlines', 'fleet_inspections', 'equipment_inspections', 'stock_summary', 'personnel_summary']],
    'GBI' => ['gbi', ['location_overview', 'fleet_summary', 'equipment_summary', 'stock_summary', 'personnel_summary']],
    'SPI' => ['spi', ['location_overview', 'fleet_summary', 'equipment_summary', 'stock_summary', 'personnel_summary']],
]);

dataset('leadership tuples', [
    ['incident_totals', 'einsatzbericht', 'read', 'yearly_incident_counts'],
    ['fleet_deadlines', 'fahrzeuge', 'read', 'inspection_deadlines'],
    ['equipment_deadlines', 'ausruestung', 'read', 'inspection_deadlines'],
    ['fleet_inspections', 'fahrzeuge', 'read', 'approved_inspection_summary'],
    ['equipment_inspections', 'ausruestung', 'read', 'approved_inspection_summary'],
    ['fleet_summary', 'fahrzeuge', 'read', 'approved_fleet_summary'],
    ['equipment_summary', 'ausruestung', 'read', 'approved_equipment_summary'],
    ['stock_summary', 'kleiderkammer', 'read', 'approved_stock_summary'],
    ['personnel_summary', 'personalverwaltung', 'read', 'approved_personnel_summary'],
    ['location_overview', 'locations', 'read', 'approved_location_overview'],
]);

it('defaults the preparatory foundation to disabled without an environment opt-in', function () {
    $environment = Env::getRepository();
    $original = $environment->get('LOCATIONS_LEADERSHIP_ENABLED');
    $environment->clear('LOCATIONS_LEADERSHIP_ENABLED');
    try {
        $settings = require base_path('extensions/Locations/config/locations.php');
        expect($settings['leadership_enabled'])->toBeFalse();
    } finally {
        $original === null
            ? $environment->clear('LOCATIONS_LEADERSHIP_ENABLED')
            : $environment->set('LOCATIONS_LEADERSHIP_ENABLED', $original);
    }
});

it('defines conservative read-only projection contracts without raw data or automatic consumers', function () {
    expect(array_keys(LeadershipAssignments::PROJECTION_FIELDS))->toBe(array_keys(LeadershipAssignments::CAPABILITIES))
        ->and(LeadershipAssignments::PROJECTION_FIELDS['incident_totals'])->toBe(['year', 'incident_count'])
        ->and(LeadershipAssignments::PROJECTION_FIELDS['personnel_summary'])->toBe(['active_member_count', 'department_counts']);
    foreach (LeadershipAssignments::CAPABILITIES as $tuple) {
        expect($tuple[1])->toBe('read');
    }
});

it('can revoke historical grants while the preparatory foundation is disabled', function () {
    $grant = ($this->grant)();
    config(['locations.leadership_enabled' => false]);
    expect($this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeFalse();
    $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Deaktivierte Grundlage bereinigt');
    expect($grant->fresh()->getAttribute('revoked_at'))->not->toBeNull();
});

it('bounds the profile by exact location module action and projection without inferred roles', function (
    string $profile, array $allowed, string $capability, string $module, string $action, string $dataClass
) {
    ($this->grant)($profile);
    expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))
        ->toBe(in_array($capability, $allowed, true))
        ->and($this->leadership->allows($this->member, $this->south->id, $module, $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, null, $module, $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, 'unknown', $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, 'export', $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, 'edit', $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, 'manage', $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, $action, 'raw'))->toBeFalse()
        ->and(DB::table('x_location_user')->where('user_id', $this->member->id)->exists())->toBeFalse()
        ->and(DB::table('module_user')->where('user_id', $this->member->id)->exists())->toBeFalse()
        ->and($this->member->hasModuleRole($module, 'wehrfuehrung'))->toBeFalse();
})->with('leadership profiles')->with('leadership tuples');

it('never permits sensitive leadership data across modules', function (string $profile, array $allowed, string $module, string $dataClass) {
    ($this->grant)($profile);
    foreach (['read', 'manage', 'export'] as $action) {
        expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))->toBeFalse();
    }
})->with('leadership profiles')->with([
    ['personalverwaltung', 'personnel'],
    ['personalverwaltung', 'personnel_file'],
    ['personalverwaltung', 'health'],
    ['personalverwaltung', 'bank'],
    ['einsatzbericht', 'aggregate_counts'],
    ['einsatzbericht', 'report'],
    ['einsatzbericht', 'raw'],
    ['fahrzeuge', 'inspection_reports'],
    ['ausruestung', 'inspection_reports'],
    ['kleiderkammer', 'personal_issuances'],
    ['mitteilungen', 'administration'],
    ['atemschutz', 'health'],
    ['verbandbuch', 'health'],
    ['finanzen', 'bank'],
    ['einsatzbericht', 'personnel'],
    ['mitteilungen', 'health'],
]);

it('requires explicit individual capabilities even within the same profile', function () {
    ($this->grant)('gbi', ['fleet_summary']);
    expect($this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeTrue()
        ->and($this->leadership->allows($this->member, $this->north->id, 'kleiderkammer', 'read', 'approved_stock_summary'))->toBeFalse();
    $this->leadership->grant($this->admin, $this->member->id, $this->south->id, 'gbi', ['stock_summary'], 'Zweiter ausdrücklicher Standort', now()->addDay()->toIso8601String());
    expect($this->leadership->allows($this->member, $this->south->id, 'kleiderkammer', 'read', 'approved_stock_summary'))->toBeTrue()
        ->and($this->leadership->allows($this->member, $this->south->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeFalse();
});

it('has no authority without an explicit assignment even for existing leadership roles', function () {
    $this->member->moduleAccess()->create(['module' => 'fahrzeuge', 'role' => 'wehrfuehrung']);
    DB::table('x_location_user')->insert(['user_id' => $this->member->id, 'location_id' => $this->north->id, 'role' => 'admin']);
    expect($this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeFalse();
});

it('rechecks fresh user location module and extension state without using cached models', function (string $change) {
    ($this->grant)('gbi', ['fleet_summary']);
    expect($this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeTrue();
    match ($change) {
        'user' => DB::table('users')->where('id', $this->member->id)->update(['is_active' => false]),
        'location' => DB::table('x_locations')->where('id', $this->north->id)->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'fahrzeuge')->update(['is_active' => false]),
        'extension' => config(['locations.enabled' => false]),
        'leadership' => config(['locations.leadership_enabled' => false]),
    };
    expect($this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary'))->toBeFalse();
})->with(['user', 'location', 'module', 'extension', 'leadership']);

it('rechecks the fresh active module for every approved projection contract', function (
    string $capability, string $module, string $action, string $dataClass
) {
    $profile = array_key_first(array_filter(LeadershipAssignments::PROFILES,
        fn (array $capabilities) => in_array($capability, $capabilities, true)));
    ($this->grant)($profile, [$capability]);
    expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))->toBeTrue();
    if ($module === LeadershipAssignments::CORE_AREA) {
        config(['locations.enabled' => false]);
    } else {
        DB::table('modules')->where('key', $module)->update(['is_active' => false]);
    }
    expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))->toBeFalse();
})->with('leadership tuples');

it('preserves all data access only for a fresh active global administrator', function () {
    config(['locations.enabled' => false, 'locations.leadership_enabled' => false]);
    foreach ([['personalverwaltung', 'personnel'], ['atemschutz', 'health'], ['finanzen', 'bank'], ['unknown', 'unknown']] as [$module, $dataClass]) {
        expect($this->leadership->allows($this->admin, null, $module, 'manage', $dataClass))->toBeTrue();
    }
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect($this->leadership->allows($this->admin, null, 'finanzen', 'read', 'bank'))->toBeFalse();
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => true, 'is_active' => false]);
    expect($this->leadership->allows($this->admin, null, 'finanzen', 'read', 'bank'))->toBeFalse();
});

it('requires fresh active global admins for both mutations', function (string $change) {
    $grant = ($this->grant)();
    match ($change) {
        'demoted' => DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]),
        'inactive' => DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]),
        'local admin' => $this->admin = $this->member,
    };
    expect(fn () => $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Test'))->toThrow(HttpException::class);
    expect(fn () => ($this->grant)('spi'))->toThrow(HttpException::class);
})->with(['demoted', 'inactive', 'local admin']);

it('rejects every new municipal grant including self-grants', function (bool $self) {
    expect(fn () => $this->leadership->grant(
        $this->admin, $self ? $this->admin->id : $this->member->id, $this->north->id, 'kommunaler_admin', ['announcement_configuration'],
        'Keine Selbstvergabe', now()->addDay()->toIso8601String(),
    ))->toThrow(ValidationException::class);
    expect(LeadershipAssignment::count())->toBe(0)
        ->and(LeadershipAssignments::PROFILES)->not->toHaveKey('kommunaler_admin')
        ->and(LeadershipAssignments::CAPABILITIES)->not->toHaveKey('announcement_configuration');
})->with([false, true]);

it('denies historical municipal grants whether approved or pending without rewriting history', function (bool $approved) {
    $grant = ($this->historicalMunicipalGrant)($approved);
    $history = $grant->getRawOriginal();
    expect($this->leadership->allows($this->member, $this->north->id, 'mitteilungen', 'manage', 'administration'))->toBeFalse()
        ->and((new LeadershipCommand)->getDefinition()->hasOption('approver'))->toBeFalse();
    foreach (LeadershipAssignments::CAPABILITIES as [$module, $action, $dataClass]) {
        expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))->toBeFalse();
    }
    expect(fn () => $this->leadership->approve($this->approver, $grant->id, $this->north->id))->toThrow(ValidationException::class);
    expect($grant->fresh()->getRawOriginal())->toBe($history);
    config(['locations.leadership_enabled' => false]);
    $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Historischen Auftrag geschlossen');
    foreach ($history as $field => $value) {
        if (! in_array($field, ['revoked_at', 'revoked_by', 'revoke_reason'], true)) {
            expect($grant->fresh()->getRawOriginal($field))->toBe($value);
        }
    }
})->with([false, true]);

it('never reactivates the retired approval action for any actor', function (string $approval) {
    $grant = ($this->historicalMunicipalGrant)();
    $actor = match ($approval) {
        'grantor' => $this->admin,
        'recipient' => $this->member,
        default => $this->approver,
    };
    if ($approval === 'demoted') {
        DB::table('users')->where('id', $this->approver->id)->update(['is_admin' => false]);
    } elseif ($approval === 'inactive') {
        DB::table('users')->where('id', $this->approver->id)->update(['is_active' => false]);
    } elseif ($approval === 'recipient') {
        DB::table('users')->where('id', $this->member->id)->update(['is_admin' => true]);
    }
    expect(fn () => $this->leadership->approve($actor, $grant->id, $this->north->id))
        ->toThrow(in_array($approval, ['demoted', 'inactive'], true) ? HttpException::class : ValidationException::class);
})->with(['grantor', 'recipient', 'demoted', 'inactive', 'independent admin']);

it('keeps exact assignment lookup while retiring approval for every profile', function () {
    $municipal = ($this->historicalMunicipalGrant)();
    expect(fn () => $this->leadership->approve($this->approver, $municipal->id, $this->south->id))->toThrow(ModelNotFoundException::class);
    $ordinary = ($this->grant)('gbi');
    expect(fn () => $this->leadership->approve($this->approver, $ordinary->id, $this->north->id))->toThrow(ValidationException::class);
});

it('requires a bounded reason and records the maximum permitted expiry', function () {
    foreach ([' ', str_repeat('x', 501)] as $reason) {
        expect(fn () => $this->leadership->grant(
            $this->admin, $this->member->id, $this->north->id, 'gbi', ['fleet_summary'],
            $reason, now()->addDay()->toIso8601String(),
        ))->toThrow(ValidationException::class);
    }
    $grant = ($this->grant)('gbi', ['fleet_summary'], now()->addDays(LeadershipAssignments::MAX_DAYS)->toIso8601String());
    expect($grant->getAttribute('expires_at')->equalTo(now()->addDays(366)))->toBeTrue();
});

it('requires active explicit grant targets and modules', function (string $change) {
    match ($change) {
        'user' => $this->member->update(['status' => MemberStatus::Inactive]),
        'location' => $this->north->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'fahrzeuge')->update(['is_active' => false]),
        'extension' => config(['locations.enabled' => false]),
        'leadership' => config(['locations.leadership_enabled' => false]),
    };
    expect(fn () => ($this->grant)('gbi', ['fleet_summary']))->toThrow(ValidationException::class);
})->with(['user', 'location', 'module', 'extension', 'leadership']);

it('locks users then sorted modules before locations consistently with stock operations', function () {
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });
    ($this->grant)('gbi', ['stock_summary', 'personnel_summary', 'equipment_summary', 'fleet_summary', 'location_overview']);
    $tables = array_map(function (array $lock) {
        preg_match('/from "([^"]+)"/', $lock['sql'], $matches);

        return $matches[1];
    }, $locks);
    expect($tables)->toBe(['users', 'users', 'modules', 'modules', 'modules', 'modules', 'x_locations']);
    $modules = array_values(array_filter($locks, fn (array $lock) => str_contains($lock['sql'], 'from "modules"')));
    expect(array_map(fn (array $lock) => $lock['bindings'][0], $modules))
        ->toBe(['ausruestung', 'fahrzeuge', 'kleiderkammer', 'personalverwaltung']);
});

it('rejects invalid capability or profile tuples and wildcard grants', function (string $profile, array $capabilities) {
    expect(fn () => ($this->grant)($profile, $capabilities))->toThrow(ValidationException::class);
})->with([
    ['gbi', []], ['gbi', ['*']], ['*', ['fleet_summary']], ['unknown', ['fleet_summary']],
    ['buergermeister', ['fleet_summary']], ['brandschutzamt', ['location_overview']],
    ['kommune', ['personnel_summary']], ['brandschutzamt', ['equipment_summary']],
    ['spi', ['fleet_inspections']], ['gbi', ['equipment_deadlines']],
    ['gbi', ['personnel']], ['gbi', ['health']], ['gbi', ['bank']], ['gbi', ['personnel_file']],
    ['kommunaler_admin', ['announcement_configuration']],
    ['kommunaler_admin', ['fleet_summary']], ['spi', ['fleet_summary', 'fleet_summary']],
    ['gbi', ['module' => 'fleet_summary']], ['gbi', ['fahrzeuge:read:personnel']],
]);

it('requires bounded future expiry and a valid beginning', function (string $end, ?string $start) {
    expect(fn () => ($this->grant)('gbi', null, $end, $start))->toThrow(ValidationException::class);
})->with([
    ['', null], ['not-a-date', null], ['2020-01-01', null],
    ['+367 days', null], ['+1 day', '+2 days'], ['+1 day', '-1 day'],
]);

it('honors future start exact expiry revocation and explicit regrant without rewriting history', function () {
    $grant = ($this->grant)('gbi', ['fleet_summary'], now()->addDays(2)->toIso8601String(), now()->addDay()->toIso8601String());
    $can = fn () => $this->leadership->allows($this->member, $this->north->id, 'fahrzeuge', 'read', 'approved_fleet_summary');
    expect($can())->toBeFalse();
    $this->travel(1)->days();
    expect($can())->toBeTrue();
    $this->travel(1)->days();
    expect($can())->toBeFalse();
    expect(fn () => ($this->grant)('gbi', ['fleet_summary']))->toThrow(ValidationException::class);
    $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Nach Ablauf ausdrücklich geschlossen');
    expect($grant->fresh()->getAttribute('revoke_reason'))->toBe('Nach Ablauf ausdrücklich geschlossen')
        ->and($grant->fresh()->getAttribute('reason'))->toBe('Befristeter Testauftrag');
    $replacement = ($this->grant)('gbi', ['fleet_summary']);
    expect($can())->toBeTrue();
    $this->leadership->revoke($this->admin, $replacement->id, $this->north->id, 'Sofort widerrufen');
    expect($can())->toBeFalse()
        ->and(LeadershipAssignment::count())->toBe(2);
    expect(fn () => $this->leadership->revoke($this->admin, $replacement->id, $this->north->id, 'Nochmals'))->toThrow(ValidationException::class);
});

it('validates exact revocation location and reason', function () {
    $grant = ($this->grant)();
    expect(fn () => $this->leadership->revoke($this->admin, $grant->id, $this->south->id, 'Falscher Standort'))->toThrow(ModelNotFoundException::class);
    foreach ([' ', str_repeat('x', 501)] as $reason) {
        expect(fn () => $this->leadership->revoke($this->admin, $grant->id, $this->north->id, $reason))->toThrow(ValidationException::class);
    }
    expect($grant->fresh()->getAttribute('revoked_at'))->toBeNull();
});

it('enforces immutable history and complete one-way revocation in PostgreSQL', function (string $mutation) {
    $grant = ($this->grant)();
    if ($mutation === 'reopen' || $mutation === 'rewrite revoke') {
        $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Abgeschlossen');
    }
    expect(fn () => DB::transaction(function () use ($grant, $mutation) {
        $query = DB::table('x_leadership_assignments')->where('id', $grant->id);
        match ($mutation) {
            'delete' => $query->delete(),
            'rewrite grant' => $query->update(['reason' => 'Umschreiben']),
            'change scope with revoke' => $query->update(['location_id' => $this->south->id, 'revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'Umgehung']),
            'missing reason' => $query->update(['revoked_at' => now(), 'revoked_by' => $this->admin->id]),
            'reopen' => $query->update(['revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null]),
            'rewrite revoke' => $query->update(['revoke_reason' => 'Umschreiben']),
        };
    }))->toThrow(QueryException::class);
})->with(['delete', 'rewrite grant', 'change scope with revoke', 'missing reason', 'reopen', 'rewrite revoke']);

it('enforces finite profile capabilities dates approval and scope at the database boundary', function (string $mutation) {
    $grant = ($this->grant)();
    $attributes = $grant->getRawOriginal();
    unset($attributes['id']);
    $attributes['location_id'] = $this->south->id;
    match ($mutation) {
        'capability' => $attributes['capabilities'] = '["bank"]',
        'profile' => $attributes['profile'] = 'unknown',
        'wildcard scope' => $attributes['location_id'] = null,
        'unbounded expiry' => $attributes['expires_at'] = null,
        'long expiry' => $attributes['expires_at'] = now()->addDays(367),
        'invalid dates' => $attributes['expires_at'] = now(),
        'partial approval' => [$attributes['profile'], $attributes['capabilities'], $attributes['approved_at']] = ['kommunaler_admin', '["announcement_configuration"]', now()],
        'self approval' => [$attributes['profile'], $attributes['capabilities'], $attributes['approved_by']] = ['kommunaler_admin', '["announcement_configuration"]', $this->admin->id],
        'preapproved insert' => [$attributes['profile'], $attributes['capabilities'], $attributes['approved_by'], $attributes['approved_at']] = ['kommunaler_admin', '["announcement_configuration"]', $this->approver->id, now()],
        'retired municipal insert' => [$attributes['profile'], $attributes['capabilities']] = ['kommunaler_admin', '["announcement_configuration"]'],
    };
    expect(fn () => DB::transaction(fn () => DB::table('x_leadership_assignments')->insert($attributes)))->toThrow(QueryException::class);
})->with(['capability', 'profile', 'wildcard scope', 'unbounded expiry', 'long expiry', 'invalid dates', 'partial approval', 'self approval', 'preapproved insert', 'retired municipal insert']);

it('preserves historical approvals and forbids any new approval in PostgreSQL', function (string $mutation) {
    $grant = ($this->historicalMunicipalGrant)(in_array($mutation, ['remove approval', 'rewrite approver'], true));
    if ($mutation === 'approve revoked') {
        $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Widerrufen');
    }
    expect(fn () => DB::transaction(function () use ($grant, $mutation) {
        $query = DB::table('x_leadership_assignments')->where('id', $grant->id);
        $approval = ['approved_by' => $this->approver->id, 'approved_at' => now()];
        match ($mutation) {
            'remove approval' => $query->update(['approved_by' => null, 'approved_at' => null]),
            'rewrite approver' => $query->update(['approved_by' => User::factory()->create(['is_admin' => true])->id]),
            'new approval' => $query->update($approval),
            'approve revoked' => $query->update($approval),
            'change scope' => $query->update([...$approval, 'location_id' => $this->south->id]),
            'combine revocation' => $query->update([...$approval, 'revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'Kombiniert']),
            'grantor approves' => $query->update(['approved_by' => $this->admin->id, 'approved_at' => now()]),
            'recipient approves' => $query->update(['approved_by' => $this->member->id, 'approved_at' => now()]),
        };
    }))->toThrow(QueryException::class);
})->with(['new approval', 'remove approval', 'rewrite approver', 'approve revoked', 'change scope', 'combine revocation', 'grantor approves', 'recipient approves']);

it('protects every historical user reference from deletion and member transfer', function (string $reference) {
    $grant = ($this->historicalMunicipalGrant)(true);
    $revoker = User::factory()->create(['is_admin' => true]);
    $this->leadership->revoke($revoker, $grant->id, $this->north->id, 'Testende');
    $user = match ($reference) {
        'recipient' => $this->member, 'grantor' => $this->admin,
        'approver' => $this->approver, 'revoker' => $revoker,
    };
    expect(fn () => app(LocationModuleRoles::class)->assertMemberDeletable($user))->toThrow(ValidationException::class);
    expect(fn () => app(MemberRemoval::class)->transferAndDelete($user, User::factory()->create(), User::factory()->create(['is_admin' => true])))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete()))->toThrow(QueryException::class);
})->with(['recipient', 'grantor', 'approver', 'revoker']);

it('upgrades the matrix without changing historical grants or approvals', function () {
    $grant = ($this->historicalMunicipalGrant)(true);
    $history = $grant->getRawOriginal();
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_29_000000_update_leadership_profile_contracts.php');
    $migration->up();
    expect($grant->fresh()->getRawOriginal())->toBe($history)
        ->and($this->leadership->allows($this->member, $this->north->id, 'mitteilungen', 'manage', 'administration'))->toBeFalse();
    ($this->grant)('brandschutzamt', ['personnel_summary', 'stock_summary', 'fleet_inspections', 'equipment_inspections']);
});

it('records an optional manifest table without claiming policy integration', function () {
    $manifest = config('locations.protection');
    expect($manifest['optional_extensions']['extensions.locations']['tables'])->toContain('x_leadership_assignments')
        ->and($manifest['tables']['x_leadership_assignments']['target_gap'])->toContain('No existing module policy')
        ->and(app(ProtectionManifest::class)->violations($manifest))->toBe([]);
});

it('supports exact administrative CLI grants and revocation without enabling old views', function () {
    $this->artisan('locations:leadership', [
        'operation' => 'grant', '--by' => (string) $this->admin->id, '--user' => (string) $this->member->id,
        '--location' => (string) $this->north->id, '--profile' => 'gbi',
        '--capability' => ['personnel_summary'], '--reason' => 'Geprüfter Auftrag',
        '--expires' => now()->addDay()->toIso8601String(),
    ])->assertSuccessful();
    $grant = LeadershipAssignment::firstOrFail();
    expect($this->leadership->allows($this->member, $this->north->id, 'personalverwaltung', 'read', 'approved_personnel_summary'))->toBeTrue()
        ->and($this->leadership->allows($this->member, $this->north->id, 'personalverwaltung', 'read', 'personnel'))->toBeFalse();
    $this->artisan('locations:leadership', [
        'operation' => 'revoke', '--by' => (string) $this->admin->id, '--assignment' => (string) $grant->id,
        '--location' => (string) $this->north->id, '--reason' => 'Auftrag beendet',
    ])->assertSuccessful();
    expect($grant->fresh()->getAttribute('revoked_at'))->not->toBeNull();
});

it('retires municipal CLI grants and approvals but permits historical revocation', function () {
    $this->artisan('locations:leadership', [
        'operation' => 'grant', '--by' => (string) $this->admin->id, '--user' => (string) $this->member->id,
        '--location' => (string) $this->north->id, '--profile' => 'kommunaler_admin',
        '--capability' => ['announcement_configuration'], '--reason' => 'Nicht vorgesehen',
        '--expires' => now()->addDay()->toIso8601String(),
    ])->assertFailed();
    $grant = ($this->historicalMunicipalGrant)();
    $this->artisan('locations:leadership', [
        'operation' => 'approve', '--by' => (string) $this->approver->id,
        '--assignment' => (string) $grant->id, '--location' => (string) $this->north->id,
    ])->expectsOutputToContain('Kommunale Administration ist nicht vorgesehen')->assertFailed();
    expect($grant->fresh()->getAttribute('approved_at'))->toBeNull();
    $this->artisan('locations:leadership', [
        'operation' => 'revoke', '--by' => (string) $this->admin->id, '--assignment' => (string) $grant->id,
        '--location' => (string) $this->north->id, '--reason' => 'Historie geschlossen',
    ])->assertSuccessful();
});

it('rejects unsafe CLI parameters inactive actors and expired grants', function (string $change) {
    $options = [
        'operation' => 'grant', '--by' => (string) $this->admin->id, '--user' => (string) $this->member->id,
        '--location' => (string) $this->north->id, '--profile' => 'gbi',
        '--capability' => ['fleet_summary'], '--reason' => 'Test', '--expires' => now()->addDay()->toIso8601String(),
    ];
    match ($change) {
        'inactive' => DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]),
        'demoted' => DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]),
        'expired' => $options['--expires'] = now()->subSecond()->toIso8601String(),
        'wildcard' => $options['--location'] = '*',
        'invalid id' => $options['--by'] = '01',
        'operation' => $options['operation'] = 'list',
        'approval operation' => $options['operation'] = 'approve',
        'bad tuple' => $options['--capability'] = ['bank'],
    };
    $this->artisan('locations:leadership', $options)->assertFailed();
    expect(LeadershipAssignment::count())->toBe(0);
})->with(['inactive', 'demoted', 'expired', 'wildcard', 'invalid id', 'operation', 'approval operation', 'bad tuple']);
