<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\Reading;
use Modules\Atemschutz\src\Models\Team;
use Modules\Atemschutz\src\Models\TeamMember;
use Modules\Atemschutz\src\Models\Warning;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Models\Report;
use Throwable;

/**
 * The Atemschutzüberwachung at the Einsatz (FwDV 7), all changes go through here:
 * 1. an Überwachung is started, optionally for an Einsatzbericht,
 * 2. Trupps are registered (RFID scan or pick from the pool) with their start pressures; a
 *    Kamerad who is not einsatzbereit can only be used with the reason noted,
 * 3. from the Anschluss the clock runs; pressures are noted at the checks after 1/3 and 2/3 of the
 *    expected time, at the target and at the end,
 * 4. state() calculates expected time, Rückzugsdruck and the warnings; each warning is kept with
 *    the time it first came up, for the protocol.
 */
class MonitorService
{
    public const MODULE = 'atemschutz';

    public const DEFAULTS = ['cylinder_liters' => 6.8, 'consumption_lpm' => 40, 'reserve_bar' => 55];

    /** A cylinder below this at the start is reported (300-bar cylinders) */
    public const LOW_START_BAR = 270;

    public const MAX_PRESSURE = 330;

    public function __construct(private readonly ReadinessService $readiness) {}

    // ─── who may do what ─────────────────────────────────────────────────────

    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    /** Leiter Atemschutz / Atemschutzwart and admins: pool, G26.3, trainings, settings */
    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'atemschutzwart'));
    }

    /** Atemschutzüberwacher: run the monitoring at the Einsatz */
    public static function canMonitor(User $user): bool
    {
        return self::canManage($user) || (self::canView($user) && $user->hasModuleRole(self::MODULE, 'ueberwacher'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'atemschutzwart'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    /** @return array{cylinder_liters: float, consumption_lpm: int, reserve_bar: int} */
    public static function settings(): array
    {
        return [
            'cylinder_liters' => (float) Setting::get('atemschutz.cylinder_liters', self::DEFAULTS['cylinder_liters']),
            'consumption_lpm' => (int) Setting::get('atemschutz.consumption_lpm', self::DEFAULTS['consumption_lpm']),
            'reserve_bar' => (int) Setting::get('atemschutz.reserve_bar', self::DEFAULTS['reserve_bar']),
        ];
    }

    public function saveSettings(User $by, float $liters, int $consumption, int $reserve): void
    {
        $this->assert(self::canManage($by), 'Das dürfen nur Atemschutzwarte und Admins.');
        $errors = array_filter([
            'cylinder_liters' => $liters < 1 || $liters > 15 ? 'Bitte ein Flaschenvolumen zwischen 1 und 15 Litern angeben.' : null,
            'consumption_lpm' => $consumption < 10 || $consumption > 150 ? 'Bitte einen Luftverbrauch zwischen 10 und 150 l/min angeben.' : null,
            'reserve_bar' => $reserve < 20 || $reserve > 150 ? 'Bitte eine Reserve zwischen 20 und 150 bar angeben.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        Setting::set('atemschutz.cylinder_liters', $liters);
        Setting::set('atemschutz.consumption_lpm', $consumption);
        Setting::set('atemschutz.reserve_bar', $reserve);
    }

    // ─── Überwachung ─────────────────────────────────────────────────────────

    public function startOperation(User $by, string $title, string $kind, ?int $reportId): Operation
    {
        $this->assert(self::canMonitor($by), 'Die Atemschutzüberwachung führen Atemschutzüberwacher und Atemschutzwarte.');
        if (! array_key_exists($kind, Operation::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Bitte Einsatz oder Übung wählen.']);
        }
        if ($reportId && (! app(ModuleRegistry::class)->isActive('einsatzbericht') || ! Report::whereKey($reportId)->exists())) {
            throw ValidationException::withMessages(['report_id' => 'Diesen Einsatzbericht gibt es nicht.']);
        }
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 150)
            : Operation::KINDS[$kind].' '.now('Europe/Berlin')->format('d.m.Y H:i');

        return Operation::create(['title' => $title, 'kind' => $kind, 'report_id' => $reportId, 'started_at' => now(), 'started_by' => $by->id]);
    }

    public function endOperation(User $by, Operation $operation): void
    {
        $this->assert(self::canMonitor($by), 'Die Atemschutzüberwachung führen Atemschutzüberwacher und Atemschutzwarte.');
        $open = $operation->teams()->where('status', '!=', 'done')->pluck('name');
        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages(['operation' => 'Noch nicht abgelegt: '.$open->implode(', ').'. Erst die Trupps beenden oder nicht gestartete entfernen.']);
        }
        $operation->update(['ended_at' => now(), 'ended_by' => $by->id]);
    }

    // ─── Trupps ──────────────────────────────────────────────────────────────

    /**
     * Register a Trupp. Members are pool Kameraden (user_id) or guests from another Wehr (name).
     *
     * @param  list<array{user_id?: ?int, name?: ?string, start_pressure: int|string, is_leader?: bool, override?: bool}>  $members
     */
    public function registerTeam(User $by, Operation $operation, string $name, ?string $task, array $members): Team
    {
        $this->assertRunning($by, $operation);
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['team_name' => 'Bitte den Trupp benennen, z. B. „Angriffstrupp 1“.']);
        }
        if (count($members) < 2 || count($members) > 4) {
            throw ValidationException::withMessages(['members' => 'Ein Trupp besteht aus zwei bis vier Atemschutzgeräteträgern.']);
        }

        $userIds = collect($members)->pluck('user_id')->filter()->map(fn ($id) => (int) $id);
        if ($userIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['members' => 'Ein Kamerad steht doppelt im Trupp.']);
        }
        $busy = TeamMember::whereIn('user_id', $userIds)->whereHas('team', fn ($q) => $q->where('status', '!=', 'done')->whereHas('operation', fn ($o) => $o->whereNull('ended_at')))
            ->with('team')->get();
        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages(['members' => $busy->map(fn ($m) => $m->name.' ist schon im '.$m->team->name)->implode(', ').'.']);
        }

        $states = $this->readiness->forWearers(Wearer::whereIn('user_id', $userIds)->get());
        $rows = collect($members)->values()->map(function (array $m, int $i) use ($states) {
            $pressure = (int) ($m['start_pressure'] ?? 0);
            if ($pressure < 1 || $pressure > self::MAX_PRESSURE) {
                throw ValidationException::withMessages(["members.$i" => 'Bitte den Flaschendruck (bar) angeben.']);
            }
            $user = filled($m['user_id'] ?? null) ? User::find((int) $m['user_id']) : null;
            $name = $user?->name ?? trim((string) ($m['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(["members.$i" => 'Bitte einen Geräteträger wählen oder den Namen angeben.']);
            }
            $state = $user ? $states[$user->id] ?? null : null;
            $reason = match (true) {
                $user === null => 'nicht im Atemschutz-Pool (z. B. andere Wehr)',
                $state === null => 'nicht im Atemschutz-Pool',
                ! $state['ready'] => implode(', ', $state['reasons']),
                default => null,
            };
            if ($reason !== null && ! ($m['override'] ?? false)) {
                throw ValidationException::withMessages(["members.$i" => $name.': '.$reason.'. Nur mit Bestätigung einsetzen.']);
            }

            return ['user_id' => $user?->id, 'name' => mb_substr($name, 0, 150), 'is_leader' => (bool) ($m['is_leader'] ?? false), 'start_pressure' => $pressure, 'override_reason' => $reason];
        });
        if ($rows->where('is_leader', true)->count() !== 1) {
            $rows = $rows->map(fn ($r, $i) => [...$r, 'is_leader' => $i === 0]);
        }

        $settings = self::settings();

        return DB::transaction(function () use ($operation, $name, $task, $rows, $settings) {
            $team = $operation->teams()->create([
                'name' => mb_substr($name, 0, 100), 'task' => filled($task) ? mb_substr(trim($task), 0, 300) : null, 'status' => 'ready',
                'cylinder_liters' => $settings['cylinder_liters'], 'consumption_lpm' => $settings['consumption_lpm'], 'reserve_bar' => $settings['reserve_bar'],
            ]);
            $team->members()->createMany($rows->all());

            return $team;
        });
    }

    /** A registered Trupp that never started can be taken off the board */
    public function removeTeam(User $by, Team $team): void
    {
        $this->assertRunning($by, $team->operation);
        $this->assert($team->status === 'ready', 'Ein gestarteter Trupp bleibt im Protokoll.');
        $team->delete();
    }

    /** Anschluss Lungenautomat: the clock starts */
    public function startTeam(User $by, Team $team): void
    {
        $this->assertRunning($by, $team->operation);
        $this->assert($team->status === 'ready', 'Der Trupp läuft schon.');

        DB::transaction(function () use ($by, $team) {
            $now = now();
            $team->update(['status' => 'active', 'started_at' => $now]);
            foreach ($team->members as $member) {
                Reading::create(['team_id' => $team->id, 'team_member_id' => $member->id, 'pressure' => $member->start_pressure, 'kind' => 'start', 'recorded_at' => $now, 'recorded_by' => $by->id]);
            }
        });
        $this->markInReport($team);
    }

    /**
     * Pressures from the radio: a check, the target reached or the end (Atemschutz abgelegt).
     *
     * @param  array<int, int|string>  $pressures  by team member id
     */
    public function recordPressures(User $by, Team $team, array $pressures, string $kind = 'check'): void
    {
        $this->assertRunning($by, $team->operation);
        $this->assert($team->isActive(), 'Der Trupp ist nicht im Einsatz.');
        $members = $team->members()->get()->keyBy('id');
        $given = collect($pressures)->filter(fn ($p) => $p !== '' && $p !== null)->map(fn ($p) => (int) $p)->only($members->keys());
        $all = in_array($kind, ['target', 'end'], true);

        if ($given->isEmpty() || ($all && $given->count() !== $members->count())) {
            throw ValidationException::withMessages(['pressures.'.$team->id => $all ? 'Bitte den Druck aller Geräteträger angeben.' : 'Bitte mindestens einen Druck angeben.']);
        }
        if ($given->contains(fn ($p) => $p < 0 || $p > self::MAX_PRESSURE)) {
            throw ValidationException::withMessages(['pressures.'.$team->id => 'Der Druck muss zwischen 0 und '.self::MAX_PRESSURE.' bar liegen.']);
        }

        DB::transaction(function () use ($by, $team, $given, $kind, $members) {
            $now = now();
            foreach ($given as $memberId => $pressure) {
                Reading::create(['team_id' => $team->id, 'team_member_id' => $memberId, 'pressure' => $pressure, 'kind' => $kind, 'recorded_at' => $now, 'recorded_by' => $by->id]);
            }
            if ($kind === 'target' && ! $team->target_reached_at) {
                $team->update(['target_reached_at' => $now, 'target_pressure' => $given->min()]);
            }
            if ($kind === 'end') {
                foreach ($given as $memberId => $pressure) {
                    $members[$memberId]->update(['end_pressure' => $pressure]);
                }
                $team->update(['status' => 'done', 'ended_at' => $now, 'withdrawn_at' => $team->withdrawn_at ?? $now]);
            }
        });
    }

    public function withdraw(User $by, Team $team): void
    {
        $this->assertRunning($by, $team->operation);
        $this->assert($team->isActive(), 'Der Trupp ist nicht im Einsatz.');
        if (! $team->withdrawn_at) {
            $team->update(['withdrawn_at' => now()]);
        }
    }

    public function acknowledge(User $by, Warning $warning): void
    {
        $this->assert(self::canMonitor($by), 'Die Atemschutzüberwachung führen Atemschutzüberwacher und Atemschutzwarte.');
        if (! $warning->acknowledged_at) {
            $warning->update(['acknowledged_at' => now(), 'acknowledged_by' => $by->id]);
        }
    }

    // ─── calculation ─────────────────────────────────────────────────────────

    /**
     * Everything the board shows about a Trupp. Expected time from the lowest start pressure:
     * (start − reserve) × cylinder litres ÷ consumption. Rückzugsdruck after the target: twice what
     * the way in took (FwDV 7), at least the reserve. Between reports the pressure is estimated
     * from the consumption.
     *
     * @return array{elapsed: ?int, expected: int, checks: array<int, array{at: int, done: bool}>, current: array<int, int>, lowest: int,
     *     estimated: int, retreat: int, minutes_to_retreat: ?int, warnings: array<string, string>}
     */
    public function state(Team $team, ?Carbon $now = null): array
    {
        $now ??= now();
        $members = $team->relationLoaded('members') ? $team->members : $team->members()->get();
        $readings = $team->relationLoaded('readings') ? $team->readings : $team->readings()->get();
        $startLowest = (int) $members->min('start_pressure');
        $expected = max(0, (int) floor(($startLowest - $team->reserve_bar) * $team->cylinder_liters / max(1, $team->consumption_lpm)));
        $elapsed = $team->started_at ? (int) floor($team->started_at->diffInSeconds($team->ended_at ?? $now) / 60) : null;

        $latest = $readings->groupBy('team_member_id')->map(fn ($r) => $r->last());
        $current = $members->mapWithKeys(fn (TeamMember $m) => [$m->id => (int) ($latest[$m->id]?->pressure ?? $m->start_pressure)])->all();
        $lowest = $current ? min($current) : 0;
        $lastAt = $readings->max('recorded_at') ?? $team->started_at;
        $sinceReading = $lastAt && $team->isActive() ? $lastAt->diffInSeconds($now) / 60 : 0;
        $estimated = max(0, (int) round($lowest - $sinceReading * $team->consumption_lpm / $team->cylinder_liters));

        $retreat = $team->target_pressure !== null
            ? max($team->reserve_bar, 2 * max(0, $startLowest - $team->target_pressure))
            : $team->reserve_bar;
        $toRetreat = $team->isActive() ? max(0, (int) floor(($estimated - $retreat) * $team->cylinder_liters / $team->consumption_lpm)) : null;

        $checks = [];
        foreach ([1 => 1 / 3, 2 => 2 / 3] as $n => $share) {
            $at = (int) floor($expected * $share);
            $point = $team->started_at?->copy()->addMinutes($at);
            $checks[$n] = ['at' => $at, 'done' => $point !== null && $readings->contains(fn (Reading $r) => $r->kind !== 'start' && $r->recorded_at->gte($point))];
        }

        $warnings = [];
        if ($team->status !== 'done' && $startLowest < self::LOW_START_BAR) {
            $warnings['low_start'] = 'Niedriger Flaschendruck beim Start: '.$startLowest.' bar';
        }
        if ($team->isActive()) {
            foreach ($checks as $n => $check) {
                if ($elapsed >= $check['at'] && ! $check['done'] && $expected > 0) {
                    $warnings['check_'.$n] = 'Druckkontrolle nach '.$n.'/3 der Zeit ('.$check['at'].' min) fällig';
                }
            }
            // Before the target the reserve warning below covers it
            if ($team->target_pressure !== null && $lowest <= $retreat && ! $team->withdrawn_at) {
                $warnings['retreat'] = 'Rückzugsdruck erreicht ('.$lowest.' bar ≤ '.$retreat.' bar) – Rückzug antreten!';
            }
            if ($lowest <= $team->reserve_bar) {
                $warnings['reserve'] = 'Restdruck an der Reserve ('.$lowest.' bar) – Trupp muss raus!';
            }
            if ($elapsed >= $expected) {
                $warnings['time'] = 'Erwartete Einsatzzeit ('.$expected.' min) überschritten';
            }
        }

        return [
            'elapsed' => $elapsed, 'expected' => $expected, 'checks' => $checks, 'current' => $current, 'lowest' => $lowest,
            'estimated' => $estimated, 'retreat' => $retreat, 'minutes_to_retreat' => $toRetreat, 'warnings' => $warnings,
        ];
    }

    /** Keeps each warning with the time it first came up */
    public function raiseWarnings(Team $team, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }
        Warning::query()->insertOrIgnore(collect($warnings)->map(fn ($message, $kind) => [
            'team_id' => $team->id, 'kind' => $kind, 'message' => mb_substr($message, 0, 300), 'raised_at' => now(),
        ])->values()->all());
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** In the linked Einsatzbericht the Kameraden of the Trupp are marked "unter Atemschutz" */
    private function markInReport(Team $team): void
    {
        $reportId = $team->operation->report_id;
        if (! $reportId || ! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return;
        }

        try {
            $report = Report::find($reportId);
            if ($report && $report->status !== 'released') {
                CrewMember::where('report_id', $reportId)->whereIn('user_id', $team->members()->whereNotNull('user_id')->pluck('user_id'))
                    ->whereHas('reportVehicle', fn ($q) => $q->where('is_standby', false))
                    ->get()->each(fn (CrewMember $crew) => $crew->update(['breathing_apparatus' => true]));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function assertRunning(User $by, Operation $operation): void
    {
        $this->assert(self::canMonitor($by), 'Die Atemschutzüberwachung führen Atemschutzüberwacher und Atemschutzwarte.');
        $this->assert($operation->isRunning(), 'Die Überwachung ist beendet.');
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['operation' => $message]);
        }
    }
}
