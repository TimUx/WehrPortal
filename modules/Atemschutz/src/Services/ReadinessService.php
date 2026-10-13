<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Atemschutz\src\Models\Team;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Notifications\DeadlineNotification;
use Modules\Atemschutz\src\Notifications\PoolDigestNotification;
use Throwable;

/**
 * Whether an Atemschutzgeräteträger may go under Atemschutz (FwDV 7): AGT-Lehrgang, valid G26.3,
 * Belastungsübung and Unterweisung within the last twelve months. The yearly Einsatz or Übung
 * under Atemschutz is shown, but doesn't block. When the Lehrgänge module is on, a completed
 * Lehrgang "Atemschutzgeräteträger…" and Belastungsübungen recorded there count too; when the
 * Einsatzbericht is on, every Einsatz in which the Kamerad is marked "unter Atemschutz" counts as
 * Einsatz under Atemschutz, also without an Überwachung.
 */
class ReadinessService
{
    public const VALID_MONTHS = 12;

    /** Shown as "läuft bald ab" this many days before */
    public const SOON_DAYS = 30;

    public const ITEMS = [
        'agt' => 'AGT-Lehrgang',
        'g263' => 'G26.3',
        'belastungsuebung' => 'Belastungsübung',
        'unterweisung' => 'Unterweisung',
        'einsatzuebung' => 'Einsatz/Übung unter AS',
    ];

    /** Missing one of these keeps a Kamerad from going under Atemschutz */
    public const BLOCKING = ['agt', 'g263', 'belastungsuebung', 'unterweisung'];

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /**
     * @return Collection<int, array{wearer: Wearer, ready: bool, soon: bool, reasons: list<string>, items: array<string, array{ok: bool, until: ?Carbon, last: ?Carbon, source: ?string}>}>
     *                                                                                                                                                                                       keyed by user id
     */
    public function forWearers(Collection $wearers): Collection
    {
        $ids = $wearers->pluck('user_id')->all();
        if ($ids === []) {
            return collect();
        }

        $own = Training::whereIn('user_id', $ids)->selectRaw('user_id, kind, max(done_on) as last')->groupBy('user_id', 'kind')->get()
            ->groupBy('user_id')->map(fn ($rows) => $rows->pluck('last', 'kind'));
        // Every Trupp a Kamerad worked in counts as Einsatz/Übung under Atemschutz
        $teams = DB::table('as_team_members')->join('as_teams', 'as_teams.id', '=', 'as_team_members.team_id')
            ->whereIn('as_team_members.user_id', $ids)->whereNotNull('as_teams.started_at')
            ->selectRaw('as_team_members.user_id, max(as_teams.started_at) as last')->groupBy('as_team_members.user_id')->pluck('last', 'user_id');
        $lq = $this->fromLehrgaenge($ids);
        $reports = $this->reportMissions($ids)->groupBy('user_id')->map(fn ($rows) => $rows->max('alarmed_at'));

        return $wearers->mapWithKeys(fn (Wearer $w) => [$w->user_id => $this->evaluate($w, $own[$w->user_id] ?? collect(), collect([$teams[$w->user_id] ?? null, $reports[$w->user_id] ?? null])->filter()->max(), $lq[$w->user_id] ?? [])]);
    }

    /** @return array{wearer: Wearer, ready: bool, soon: bool, reasons: list<string>, items: array<string, array{ok: bool, until: ?Carbon, last: ?Carbon, source: ?string}>} */
    public function forWearer(Wearer $wearer): array
    {
        return $this->forWearers(collect([$wearer]))->first();
    }

    /** @return array{year: int, minutes: int, teams: int} time under Atemschutz this year, from the Trupps of the Überwachung */
    public function wearTime(User $user, ?int $year = null): array
    {
        $year ??= (int) now('Europe/Berlin')->year;
        $teams = Team::whereNotNull('started_at')->whereYear('started_at', $year)
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->get();

        return ['year' => $year, 'minutes' => (int) $teams->sum(fn (Team $t) => $t->minutes()), 'teams' => $teams->count()];
    }

    /** @return array{ready: int, soon: int, not_ready: int} the pool in numbers */
    public function poolCounts(): array
    {
        $states = $this->forWearers(Wearer::active()->get());

        return [
            'ready' => $states->where('ready', true)->where('soon', false)->count(),
            'soon' => $states->where('ready', true)->where('soon', true)->count(),
            'not_ready' => $states->where('ready', false)->count(),
        ];
    }

    /**
     * Daily: each Kamerad gets one mail per deadline that runs out within SOON_DAYS (or has run out),
     * the Atemschutzwarte one overview of all of them.
     */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(MonitorService::MODULE)) {
            return 0;
        }

        $limit = self::today()->addDays(self::SOON_DAYS);
        $digest = collect();

        foreach ($this->forWearers(Wearer::active()->with('user')->get()) as $state) {
            $wearer = $state['wearer'];
            $reminded = $wearer->reminded ?? [];
            $due = collect($state['items'])->only(self::BLOCKING)
                ->filter(fn ($item, $key) => $item['until'] && $item['until']->lte($limit) && ($reminded[$key] ?? null) !== $item['until']->toDateString());
            if ($due->isEmpty()) {
                continue;
            }

            // Claim first: a second scheduler finds the dates already noted
            $claimed = DB::table('as_wearers')->where('id', $wearer->id)->where('updated_at', $wearer->updated_at)
                ->update(['reminded' => json_encode([...$reminded, ...$due->map(fn ($i) => $i['until']->toDateString())->all()]), 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }

            $lines = $due->map(fn ($item, $key) => self::ITEMS[$key].($item['until']->lt(self::today()) ? ' abgelaufen am ' : ' läuft ab am ').$item['until']->format('d.m.Y'))->values();
            $digest->push(['name' => $wearer->user->name, 'lines' => $lines]);
            $this->notify(collect([$wearer->user]), new DeadlineNotification($lines->all()));
        }

        if ($digest->isNotEmpty()) {
            $this->notify(app(MonitorService::class)->managers(), new PoolDigestNotification($digest));
        }

        return $digest->count();
    }

    private function evaluate(Wearer $wearer, Collection $own, ?string $lastTeam, array $lq): array
    {
        $today = self::today();
        $date = fn ($value) => $value ? Carbon::parse(Carbon::parse($value)->setTimezone('Europe/Berlin')->toDateString()) : null;
        $latest = fn (?Carbon ...$dates) => collect($dates)->filter()->sortDesc()->first();
        $yearly = function (?Carbon $last, ?string $source = null) use ($today) {
            $until = $last?->copy()->addMonths(self::VALID_MONTHS);

            return ['ok' => $until !== null && $until->gte($today), 'until' => $until, 'last' => $last, 'source' => $source];
        };

        $agtLast = $latest($date($wearer->agt_qualified_on), $date($lq['agt'] ?? null));
        $belOwn = $date($own['belastungsuebung'] ?? null);
        $belLq = $date($lq['belastungsuebung'] ?? null);
        $bel = $latest($belOwn, $belLq);
        $g263 = $wearer->g263_valid_until ? Carbon::parse($wearer->g263_valid_until->toDateString()) : null;

        $items = [
            'agt' => ['ok' => $wearer->is_agt || isset($lq['agt']), 'until' => null, 'last' => $agtLast, 'source' => ! $wearer->is_agt && isset($lq['agt']) ? 'Lehrgänge' : null],
            'g263' => ['ok' => $g263 !== null && $g263->gte($today), 'until' => $g263, 'last' => $date($wearer->g263_examined_on), 'source' => null],
            'belastungsuebung' => $yearly($bel, $belLq && (! $belOwn || $belLq->gt($belOwn)) ? 'Lehrgänge' : null),
            'unterweisung' => $yearly($date($own['unterweisung'] ?? null)),
            'einsatzuebung' => $yearly($latest($date($own['einsatzuebung'] ?? null), $date($lastTeam))),
        ];

        $reasons = collect(self::BLOCKING)->reject(fn ($key) => $items[$key]['ok'])
            ->map(fn ($key) => self::ITEMS[$key].($items[$key]['until'] ? ' abgelaufen' : ' fehlt'))->values()->all();
        $ready = $reasons === [];
        $soonLimit = $today->copy()->addDays(self::SOON_DAYS);

        return [
            'wearer' => $wearer, 'ready' => $ready, 'items' => $items, 'reasons' => $reasons,
            'soon' => $ready && collect(self::BLOCKING)->contains(fn ($key) => $items[$key]['until']?->lte($soonLimit)),
        ];
    }

    /**
     * Einsätze from the Einsatzbericht (started, not a draft) in which the Kamerad is marked "unter
     * Atemschutz", newest first: user_id, report_id, number, keyword, alarmed_at
     */
    public function reportMissions(array $userIds, ?int $limit = null): Collection
    {
        if ($userIds === [] || ! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        try {
            return DB::table('eb_report_crew')->join('eb_reports', 'eb_reports.id', '=', 'eb_report_crew.report_id')
                ->whereIn('eb_report_crew.user_id', $userIds)->where('eb_report_crew.breathing_apparatus', true)
                ->where('eb_reports.status', '!=', 'draft')->whereNotNull('eb_reports.alarmed_at')
                ->orderByDesc('eb_reports.alarmed_at')->when($limit, fn ($q) => $q->limit($limit))
                ->get(['eb_report_crew.user_id', 'eb_reports.id as report_id', 'eb_reports.number', 'eb_reports.keyword', 'eb_reports.alarmed_at']);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    /** @return array<int, array{agt?: string, belastungsuebung?: string}> completed Lehrgänge by user */
    private function fromLehrgaenge(array $userIds): array
    {
        if (! app(ModuleRegistry::class)->isActive('lehrgaenge')) {
            return [];
        }

        try {
            $rows = DB::table('lq_records')->join('lq_courses', 'lq_courses.id', '=', 'lq_records.course_id')
                ->whereIn('lq_records.user_id', $userIds)->where('lq_records.status', 'completed')
                ->where(fn ($q) => $q->where('lq_courses.name', 'ilike', 'Atemschutzgeräteträger%')->orWhere('lq_courses.name', 'ilike', '%Belastungsübung%'))
                ->get(['lq_records.user_id', 'lq_records.completed_on', 'lq_records.updated_at', 'lq_courses.name']);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $key = stripos($row->name, 'Belastungsübung') !== false ? 'belastungsuebung' : 'agt';
            $when = $row->completed_on ?? substr((string) $row->updated_at, 0, 10);
            if (! isset($result[$row->user_id][$key]) || $when > $result[$row->user_id][$key]) {
                $result[$row->user_id][$key] = $when;
            }
        }

        return $result;
    }

    private function notify(Collection $users, $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
