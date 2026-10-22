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
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    config(['locations.enabled' => true, 'locations.leadership_enabled' => true]);
    Module::whereIn('key', ['einsatzbericht', 'fahrzeuge', 'ausruestung', 'kleiderkammer', 'mitteilungen'])
        ->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->leadership = app(LeadershipAssignments::class);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->approver = User::factory()->create(['is_admin' => true]);
    $this->member = User::factory()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'leadership-north']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'leadership-south']);
    $this->grant = fn (string $profile = 'gbi', ?array $capabilities = null, ?string $expires = null, ?string $start = null) => $this->leadership->grant(
        $this->admin, $this->member->id, $this->north->id, $profile,
        $capabilities ?? LeadershipAssignments::PROFILES[$profile], 'Befristeter Testauftrag',
        $expires ?? now()->addDay()->toIso8601String(), $start,
    );
});

dataset('leadership profiles', [
    'mayor' => ['buergermeister', ['incident_totals']],
    'municipality' => ['kommune', ['incident_totals']],
    'fire authority' => ['brandschutzamt', ['fleet_deadlines', 'equipment_deadlines']],
    'GBI' => ['gbi', ['fleet_summary', 'stock_summary']],
    'SPI' => ['spi', ['fleet_summary', 'stock_summary']],
    'municipal administrator' => ['kommunaler_admin', ['announcement_configuration']],
]);

dataset('leadership tuples', [
    ['incident_totals', 'einsatzbericht', 'read', 'aggregate_counts'],
    ['fleet_deadlines', 'fahrzeuge', 'read', 'inspection_deadlines'],
    ['equipment_deadlines', 'ausruestung', 'read', 'inspection_deadlines'],
    ['fleet_summary', 'fahrzeuge', 'read', 'approved_fleet_summary'],
    ['stock_summary', 'kleiderkammer', 'read', 'approved_stock_summary'],
    ['announcement_configuration', 'mitteilungen', 'manage', 'administration'],
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
    $grant = ($this->grant)($profile);
    if ($profile === 'kommunaler_admin') {
        $this->leadership->approve($this->approver, $grant->id, $this->north->id);
    }
    expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))
        ->toBe(in_array($capability, $allowed, true))
        ->and($this->leadership->allows($this->member, $this->south->id, $module, $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, null, $module, $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, 'unknown', $action, $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, 'export', $dataClass))->toBeFalse()
        ->and($this->leadership->allows($this->member, $this->north->id, $module, $action, 'raw'))->toBeFalse()
        ->and(DB::table('x_location_user')->where('user_id', $this->member->id)->exists())->toBeFalse()
        ->and(DB::table('module_user')->where('user_id', $this->member->id)->exists())->toBeFalse()
        ->and($this->member->hasModuleRole($module, 'wehrfuehrung'))->toBeFalse();
})->with('leadership profiles')->with('leadership tuples');

it('never permits sensitive leadership data across modules', function (string $profile, array $allowed, string $module, string $dataClass) {
    $grant = ($this->grant)($profile);
    if ($profile === 'kommunaler_admin') {
        $this->leadership->approve($this->approver, $grant->id, $this->north->id);
    }
    foreach (['read', 'manage', 'export'] as $action) {
        expect($this->leadership->allows($this->member, $this->north->id, $module, $action, $dataClass))->toBeFalse();
    }
})->with('leadership profiles')->with([
    ['personalverwaltung', 'personnel'],
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

it('rejects municipal self-grants', function () {
    expect(fn () => $this->leadership->grant(
        $this->admin, $this->admin->id, $this->north->id, 'kommunaler_admin', ['announcement_configuration'],
        'Keine Selbstvergabe', now()->addDay()->toIso8601String(),
    ))->toThrow(ValidationException::class);
});

it('keeps municipal grants pending until a distinct separate approval action', function () {
    $grant = ($this->grant)('kommunaler_admin');
    $can = fn () => $this->leadership->allows($this->member, $this->north->id, 'mitteilungen', 'manage', 'administration');
    expect($can())->toBeFalse()->and($grant->getAttribute('approved_by'))->toBeNull()
        ->and((new LeadershipCommand)->getDefinition()->hasOption('approver'))->toBeFalse();
    $this->leadership->approve($this->approver, $grant->id, $this->north->id);
    expect($can())->toBeTrue()
        ->and($grant->fresh()->getAttribute('approved_by'))->toBe($this->approver->id)
        ->and($grant->fresh()->getAttribute('approved_at')->equalTo(now()))->toBeTrue();
    expect(fn () => $this->leadership->approve($this->approver, $grant->id, $this->north->id))->toThrow(ValidationException::class);
});

it('requires a second fresh active independent global admin for municipal administration', function (string $approval) {
    $grant = ($this->grant)('kommunaler_admin');
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
})->with(['grantor', 'recipient', 'demoted', 'inactive']);

it('rejects approval when grant target module location dates or foundation are no longer eligible', function (string $change) {
    $grant = ($this->grant)('kommunaler_admin');
    match ($change) {
        'expired' => $this->travel(1)->days(),
        'revoked' => $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Widerrufen'),
        'user' => DB::table('users')->where('id', $this->member->id)->update(['is_active' => false]),
        'location' => DB::table('x_locations')->where('id', $this->north->id)->update(['is_active' => false]),
        'module' => DB::table('modules')->where('key', 'mitteilungen')->update(['is_active' => false]),
        'extension' => config(['locations.enabled' => false]),
        'leadership' => config(['locations.leadership_enabled' => false]),
    };
    expect(fn () => $this->leadership->approve($this->approver, $grant->id, $this->north->id))->toThrow(ValidationException::class);
    expect($grant->fresh()->getAttribute('approved_by'))->toBeNull();
})->with(['expired', 'revoked', 'user', 'location', 'module', 'extension', 'leadership']);

it('requires the exact approval location and municipal profile', function () {
    $municipal = ($this->grant)('kommunaler_admin');
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

it('rejects invalid capability or profile tuples and wildcard grants', function (string $profile, array $capabilities) {
    expect(fn () => ($this->grant)($profile, $capabilities))->toThrow(ValidationException::class);
})->with([
    ['gbi', []], ['gbi', ['*']], ['*', ['fleet_summary']], ['unknown', ['fleet_summary']],
    ['buergermeister', ['fleet_summary']], ['brandschutzamt', ['stock_summary']],
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
    };
    expect(fn () => DB::transaction(fn () => DB::table('x_leadership_assignments')->insert($attributes)))->toThrow(QueryException::class);
})->with(['capability', 'profile', 'wildcard scope', 'unbounded expiry', 'long expiry', 'invalid dates', 'partial approval', 'self approval', 'preapproved insert']);

it('enforces one-way independent approval separately from revocation in PostgreSQL', function (string $mutation) {
    $grant = ($this->grant)('kommunaler_admin');
    if (in_array($mutation, ['remove approval', 'rewrite approver'], true)) {
        $this->leadership->approve($this->approver, $grant->id, $this->north->id);
    }
    if ($mutation === 'approve revoked') {
        $this->leadership->revoke($this->admin, $grant->id, $this->north->id, 'Widerrufen');
    }
    expect(fn () => DB::transaction(function () use ($grant, $mutation) {
        $query = DB::table('x_leadership_assignments')->where('id', $grant->id);
        $approval = ['approved_by' => $this->approver->id, 'approved_at' => now()];
        match ($mutation) {
            'remove approval' => $query->update(['approved_by' => null, 'approved_at' => null]),
            'rewrite approver' => $query->update(['approved_by' => User::factory()->create(['is_admin' => true])->id]),
            'approve revoked' => $query->update($approval),
            'change scope' => $query->update([...$approval, 'location_id' => $this->south->id]),
            'combine revocation' => $query->update([...$approval, 'revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'Kombiniert']),
            'grantor approves' => $query->update(['approved_by' => $this->admin->id, 'approved_at' => now()]),
            'recipient approves' => $query->update(['approved_by' => $this->member->id, 'approved_at' => now()]),
        };
    }))->toThrow(QueryException::class);
})->with(['remove approval', 'rewrite approver', 'approve revoked', 'change scope', 'combine revocation', 'grantor approves', 'recipient approves']);

it('protects every historical user reference from deletion and member transfer', function (string $reference) {
    $grant = ($this->grant)('kommunaler_admin');
    $this->leadership->approve($this->approver, $grant->id, $this->north->id);
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

it('records an optional manifest table without claiming policy integration', function () {
    $manifest = config('locations.protection');
    expect($manifest['optional_extensions']['extensions.locations']['tables'])->toContain('x_leadership_assignments')
        ->and($manifest['tables']['x_leadership_assignments']['target_gap'])->toContain('No existing module policy')
        ->and(app(ProtectionManifest::class)->violations($manifest))->toBe([]);
});

it('supports exact administrative CLI grants and revocation without enabling old views', function () {
    $this->artisan('locations:leadership', [
        'operation' => 'grant', '--by' => (string) $this->admin->id, '--user' => (string) $this->member->id,
        '--location' => (string) $this->north->id, '--profile' => 'kommunaler_admin',
        '--capability' => ['announcement_configuration'], '--reason' => 'Geprüfter Auftrag',
        '--expires' => now()->addDay()->toIso8601String(),
    ])->assertSuccessful();
    $grant = LeadershipAssignment::firstOrFail();
    expect($this->leadership->allows($this->member, $this->north->id, 'mitteilungen', 'manage', 'administration'))->toBeFalse();
    $this->artisan('locations:leadership', [
        'operation' => 'approve', '--by' => (string) $this->approver->id, '--assignment' => (string) $grant->id,
        '--location' => (string) $this->north->id,
    ])->assertSuccessful();
    expect($this->leadership->allows($this->member, $this->north->id, 'mitteilungen', 'manage', 'administration'))->toBeTrue();
    $this->artisan('locations:leadership', [
        'operation' => 'revoke', '--by' => (string) $this->admin->id, '--assignment' => (string) $grant->id,
        '--location' => (string) $this->north->id, '--reason' => 'Auftrag beendet',
    ])->assertSuccessful();
    expect($grant->fresh()->getAttribute('revoked_at'))->not->toBeNull();
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
