<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Extensions\Locations\Models\LeadershipAssignment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Explicit contracts for LeadershipProjections, not an adapter for existing policies.
 * A capability decision alone is
 * neither raw-object authorization nor an aggregation/anonymization guarantee.
 */
class LeadershipAssignments
{
    public const MAX_DAYS = 366;

    /** Core location metadata is an extension area, not a fictitious modules-table entry. */
    public const CORE_AREA = 'locations';

    public const CAPABILITIES = [
        'incident_totals' => ['einsatzbericht', 'read', 'yearly_incident_counts'],
        'fleet_deadlines' => ['fahrzeuge', 'read', 'inspection_deadlines'],
        'equipment_deadlines' => ['ausruestung', 'read', 'inspection_deadlines'],
        'fleet_inspections' => ['fahrzeuge', 'read', 'approved_inspection_summary'],
        'equipment_inspections' => ['ausruestung', 'read', 'approved_inspection_summary'],
        'fleet_summary' => ['fahrzeuge', 'read', 'approved_fleet_summary'],
        'equipment_summary' => ['ausruestung', 'read', 'approved_equipment_summary'],
        'stock_summary' => ['kleiderkammer', 'read', 'approved_stock_summary'],
        'personnel_summary' => ['personalverwaltung', 'read', 'approved_personnel_summary'],
        'location_overview' => [self::CORE_AREA, 'read', 'approved_location_overview'],
    ];

    /**
     * Allowlisted output fields, not permission to load raw records.
     * Counts must cover exactly the authorized location; incident counts are yearly only.
     * No personal names, health/bank data, personnel files, free text, attachments or drill-down links.
     * Inspection results and asset statuses are fixed categorical count buckets.
     */
    public const PROJECTION_FIELDS = [
        'incident_totals' => ['year', 'incident_count'],
        'fleet_deadlines' => ['overdue', 'due_within_30_days', 'later', 'undated'],
        'equipment_deadlines' => ['overdue', 'due_within_30_days', 'later', 'undated'],
        'fleet_inspections' => ['year', 'passed', 'limited', 'failed', 'unknown'],
        'equipment_inspections' => ['year', 'passed', 'failed'],
        'fleet_summary' => ['vehicle_count', 'status_counts'],
        'equipment_summary' => ['equipment_count', 'status_counts'],
        'stock_summary' => ['stock_quantity', 'tracked_item_count'],
        'personnel_summary' => ['active_member_count', 'department_counts'],
        'location_overview' => ['location_name', 'short_name'],
    ];

    public const PROFILES = [
        'buergermeister' => ['incident_totals'],
        'kommune' => ['incident_totals'],
        'brandschutzamt' => ['fleet_deadlines', 'equipment_deadlines', 'fleet_inspections', 'equipment_inspections', 'stock_summary', 'personnel_summary'],
        'gbi' => ['location_overview', 'fleet_summary', 'equipment_summary', 'stock_summary', 'personnel_summary'],
        'spi' => ['location_overview', 'fleet_summary', 'equipment_summary', 'stock_summary', 'personnel_summary'],
    ];

    /** Capability decision only. No membership, session, loaded relation or cached state grants it. */
    public function allows(User $user, ?int $locationId, string $module, string $action, string $dataClass): bool
    {
        $actor = DB::table('users')->where('id', $user->id)->first(['is_active', 'is_admin']);
        if (! $actor?->is_active) {
            return false;
        }
        // The existing global administrator remains deliberately unrestricted.
        if ($actor->is_admin) {
            return true;
        }
        $capability = array_search([$module, $action, $dataClass], self::CAPABILITIES, true);
        if ($capability === false || $locationId === null || ! config('locations.enabled') || ! config('locations.leadership_enabled')
            || ! Schema::hasTable('x_leadership_assignments') || ! $this->activeModule($module)) {
            return false;
        }

        return DB::table('x_leadership_assignments as grants')
            ->join('x_locations as location', 'location.id', '=', 'grants.location_id')
            ->where('location.is_active', true)->where('grants.location_id', $locationId)
            ->where('grants.user_id', $user->id)->whereNull('grants.revoked_at')
            ->where('grants.valid_from', '<=', now())->where('grants.expires_at', '>', now())
            ->whereIn('grants.profile', array_keys(array_filter(self::PROFILES,
                fn (array $capabilities) => in_array($capability, $capabilities, true))))
            ->whereJsonContains('grants.capabilities', $capability)->exists();
    }

    public function grant(User $by, int $userId, int $locationId, string $profile, array $capabilities, string $reason, string $expiresAt, ?string $validFrom = null): LeadershipAssignment
    {
        return DB::transaction(function () use ($by, $userId, $locationId, $profile, $capabilities, $reason, $expiresAt, $validFrom) {
            // Lock identities in a stable order.
            DB::table('users')->whereIn('id', [$by->id, $userId])->orderBy('id')->lockForUpdate()->get();
            $this->authorize($by->id);
            if (! config('locations.leadership_enabled')) {
                throw ValidationException::withMessages(['profile' => 'Die vorbereitete Führungsprofil-Grundlage ist deaktiviert; es gibt keine freigegebenen Modulansichten.']);
            }
            $reason = trim($reason);
            Validator::make(['reason' => $reason, 'expires_at' => $expiresAt, 'valid_from' => $validFrom], [
                'reason' => ['required', 'string', 'max:500'],
                'expires_at' => ['required', 'date'],
                'valid_from' => ['nullable', 'date'],
            ])->validate();
            $now = CarbonImmutable::now();
            $start = $validFrom === null ? $now : CarbonImmutable::parse($validFrom)->utc();
            $end = CarbonImmutable::parse($expiresAt)->utc();
            if ($start->lt($now) || $end->lte($start) || $end->gt($now->addDays(self::MAX_DAYS))) {
                throw ValidationException::withMessages(['expires_at' => 'Beginn ab jetzt und Ende danach, höchstens 366 Tage ab Vergabe, erforderlich.']);
            }
            if (! array_key_exists($profile, self::PROFILES) || ! array_is_list($capabilities) || $capabilities === []
                || array_filter($capabilities, fn ($capability) => ! is_string($capability)) !== []
                || count(array_unique($capabilities)) !== count($capabilities)
                || array_diff($capabilities, self::PROFILES[$profile]) !== []) {
                throw ValidationException::withMessages(['capabilities' => 'Nur ausdrücklich erlaubte Profil-Capabilities ohne Platzhalter zulässig.']);
            }
            $modules = array_unique(array_map(fn (string $capability) => self::CAPABILITIES[$capability][0], $capabilities));
            sort($modules);
            foreach ($modules as $module) {
                if (! $this->activeModule($module, true)) {
                    throw ValidationException::withMessages(['capabilities' => 'Alle betroffenen Module müssen aktiv sein.']);
                }
            }
            $location = DB::table('x_locations')->where('id', $locationId)->lockForUpdate()->first();
            $member = DB::table('users')->where('id', $userId)->first();
            if (! config('locations.enabled') || ! $location?->is_active || ! $member?->is_active) {
                throw ValidationException::withMessages(['location_id' => 'Locations, aktiver Standort und aktive Person erforderlich.']);
            }
            if (LeadershipAssignment::where('user_id', $userId)->where('location_id', $locationId)
                ->where('profile', $profile)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['profile' => 'Bestehende Vergabe zuerst ausdrücklich widerrufen, auch nach Ablauf.']);
            }

            return LeadershipAssignment::create([
                'user_id' => $userId, 'location_id' => $locationId, 'profile' => $profile,
                'capabilities' => $capabilities, 'granted_by' => $by->id, 'granted_at' => $now,
                'reason' => $reason,
                'valid_from' => $start, 'expires_at' => $end,
            ]);
        });
    }

    /** @deprecated Municipal administration is not supported; historical approvals remain immutable. */
    public function approve(User $by, int $assignmentId, int $locationId): void
    {
        DB::transaction(function () use ($by, $assignmentId, $locationId) {
            $this->authorize($by->id);
            LeadershipAssignment::where('location_id', $locationId)->lockForUpdate()->findOrFail($assignmentId);
            throw ValidationException::withMessages(['assignment' => 'Kommunale Administration ist nicht vorgesehen; historische Vergaben können nur widerrufen werden.']);
        });
    }

    public function revoke(User $by, int $assignmentId, int $locationId, string $reason): void
    {
        DB::transaction(function () use ($by, $assignmentId, $locationId, $reason) {
            $this->authorize($by->id);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();
            $grant = LeadershipAssignment::where('location_id', $locationId)->lockForUpdate()->findOrFail($assignmentId);
            if ($grant->getAttribute('revoked_at') !== null) {
                throw ValidationException::withMessages(['assignment' => 'Diese Vergabe wurde bereits widerrufen.']);
            }
            $grant->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'revoke_reason' => $reason]);
        });
    }

    public function projectionEnabled(string $module): bool
    {
        return config('locations.enabled') && config('locations.leadership_enabled') && $this->activeModule($module);
    }

    private function authorize(int $userId): void
    {
        $actor = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        abort_unless($actor?->is_active && $actor->is_admin, 403);
    }

    private function activeModule(string $module, bool $lock = false): bool
    {
        if ($module === self::CORE_AREA) {
            return (bool) config('locations.enabled');
        }

        return app(ModuleRegistry::class)->has($module)
            && DB::table('modules')->where('key', $module)->where('is_active', true)
                ->when($lock, fn (Builder $query) => $query->lockForUpdate())->first() !== null;
    }
}
