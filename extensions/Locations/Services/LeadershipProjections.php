<?php

namespace Extensions\Locations\Services;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded, read-only summaries. Counts are not a promise of anonymity in small locations. */
class LeadershipProjections
{
    public function read(User $actor, int $locationId, string $capability, ?int $year = null): array
    {
        $tuple = LeadershipAssignments::CAPABILITIES[$capability] ?? null;
        abort_unless($tuple !== null, 403);
        $assignments = app(LeadershipAssignments::class);
        $authorize = function () use ($assignments, $actor, $locationId, $tuple) {
            abort_unless($assignments->projectionEnabled($tuple[0])
                && DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()
                && $assignments->allows($actor, $locationId, ...$tuple), 403);
        };
        $authorize();
        $annual = in_array($capability, ['incident_totals', 'fleet_inspections', 'equipment_inspections'], true);
        if (($annual && ($year === null || $year < 1900 || $year > CarbonImmutable::now('Europe/Berlin')->year))
            || (! $annual && $year !== null)) {
            throw ValidationException::withMessages(['year' => 'Jahresauswertungen benötigen genau ein Jahr von 1900 bis heute; andere Übersichten erlauben kein Jahr.']);
        }

        $result = match ($capability) {
            'incident_totals' => $this->incidents($locationId, (int) $year),
            'fleet_summary' => $this->statuses(
                DB::table('vehicles as asset')->leftJoin('fv_profiles as profile', 'profile.vehicle_id', '=', 'asset.id')
                    ->where('asset.ext_location_id', $locationId),
                'profile.status', ['ready', 'limited', 'unavailable'], 'vehicle_count'),
            'equipment_summary' => $this->statuses(DB::table('ar_equipment')->where('ext_location_id', $locationId),
                'status', ['available', 'loaned', 'defective', 'in_repair', 'locked', 'retired'], 'equipment_count'),
            'fleet_deadlines' => $this->deadlines(DB::table('fv_deadlines as deadline')
                ->join('vehicles as asset', 'asset.id', '=', 'deadline.vehicle_id')
                ->where('asset.ext_location_id', $locationId)->where('deadline.is_active', true), 'deadline.due_on'),
            'equipment_deadlines' => $this->deadlines(DB::table('ar_equipment')->where('ext_location_id', $locationId), 'next_inspection_at'),
            'fleet_inspections' => $this->fleetInspections($locationId, (int) $year),
            'equipment_inspections' => $this->equipmentInspections($locationId, (int) $year),
            'stock_summary' => [
                'stock_quantity' => (int) DB::table('kk_stock')->where('ext_location_id', $locationId)->sum('quantity'),
                'tracked_item_count' => DB::table('kk_items')->where('ext_location_id', $locationId)->whereNull('deleted_at')->count(),
            ],
            'personnel_summary' => $this->personnel($locationId),
            'location_overview' => (array) DB::table('x_locations')->where('id', $locationId)
                ->select(['name as location_name', 'short_name'])->first(),
            default => abort(403),
        };
        // Never reuse a decision from the start of a long query after a completed revocation.
        $authorize();

        return $result;
    }

    private function incidents(int $locationId, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin');

        return [
            'year' => $year,
            'incident_count' => DB::table('eb_reports')->where('ext_location_id', $locationId)->whereNull('parent_id')
                ->whereIn('status', Setting::get('einsatzbericht.released_only', '1') === '1' ? ['released'] : ['closed', 'released'])
                ->where('alarmed_at', '>=', $from->utc())->where('alarmed_at', '<', $from->addYear()->utc())->count(),
        ];
    }

    private function statuses(Builder $query, string $column, array $allowed, string $totalKey): array
    {
        $counts = array_fill_keys([...$allowed, 'unknown'], 0);
        foreach ($allowed as $status) {
            $counts[$status] = (clone $query)->where($column, $status)->count();
        }
        $counts['unknown'] = (clone $query)->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, $allowed))->count();

        return [$totalKey => array_sum($counts), 'status_counts' => $counts];
    }

    private function deadlines(Builder $query, string $column): array
    {
        $today = CarbonImmutable::now('Europe/Berlin')->toDateString();
        $until = CarbonImmutable::now('Europe/Berlin')->addDays(30)->toDateString();

        return [
            'overdue' => (clone $query)->where($column, '<', $today)->count(),
            'due_within_30_days' => (clone $query)->whereBetween($column, [$today, $until])->count(),
            'later' => (clone $query)->where($column, '>', $until)->count(),
            'undated' => (clone $query)->whereNull($column)->count(),
        ];
    }

    private function fleetInspections(int $locationId, int $year): array
    {
        $query = DB::table('fv_records as inspection')->join('vehicles as asset', 'asset.id', '=', 'inspection.vehicle_id')
            ->where('asset.ext_location_id', $locationId)->where('inspection.kind', 'inspection')
            ->where('inspection.done_on', '>=', $year.'-01-01')->where('inspection.done_on', '<', ($year + 1).'-01-01');
        $summary = $this->statuses($query, 'inspection.result', ['passed', 'limited', 'failed'], 'count');

        return ['year' => $year, ...$summary['status_counts']];
    }

    private function equipmentInspections(int $locationId, int $year): array
    {
        $query = DB::table('ar_inspections as inspection')->join('ar_equipment as asset', 'asset.id', '=', 'inspection.equipment_id')
            ->where('asset.ext_location_id', $locationId)
            ->where('inspection.inspected_at', '>=', $year.'-01-01')->where('inspection.inspected_at', '<', ($year + 1).'-01-01');

        return ['year' => $year, 'passed' => (clone $query)->where('inspection.passed', true)->count(),
            'failed' => (clone $query)->where('inspection.passed', false)->count()];
    }

    private function members(int $locationId): Builder
    {
        return DB::table('users as member')->where('member.is_active', true)
            ->where(fn (Builder $q) => $q->where('member.ext_location_id', $locationId)
                ->orWhereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('x_location_user as membership')
                    ->whereColumn('membership.user_id', 'member.id')->where('membership.location_id', $locationId)));
    }

    private function personnel(int $locationId): array
    {
        $groups = DB::table('groups')->where('ext_location_id', $locationId)->orderBy('id')->limit(101)->pluck('id');
        if ($groups->count() > 100) {
            throw ValidationException::withMessages(['location' => 'Zu viele Abteilungen für diese begrenzte Übersicht (maximal 100).']);
        }
        $departments = [];
        foreach ($groups as $groupId) {
            $departments[] = ['department_id' => (int) $groupId, 'member_count' => $this->members($locationId)
                ->join('group_user as department', 'department.user_id', '=', 'member.id')->where('department.group_id', $groupId)->count()];
        }

        return ['active_member_count' => $this->members($locationId)->count(), 'department_counts' => $departments];
    }
}
