<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\User;
use App\Services\LocationNotifications;
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

    /** CSA-Träger additionally need the CSA training and a CSA-Übung within twelve months, on top of being ready for Atemschutz */
    public const CSA_ITEMS = ['csa' => 'CSA-Ausbildung', 'csa_uebung' => 'CSA-Übung'];

    /** Tragezeit by kind, for the pass, the Personalakte and the Jahresbericht */
    public const WEAR_KINDS = ['einsatz' => 'Einsatz', 'uebung' => 'Übung', 'belastungsuebung' => 'Belastungsübung', 'csa' => 'mit CSA'];

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
        // An Unterweisung of the module Unterweisungen marked "zählt als Atemschutz-Unterweisung" counts too
        foreach ($this->fromUnterweisungen($ids) as $userId => $day) {
            $known = $own->get($userId, collect());
            $own->put($userId, $known->put('unterweisung', max((string) $known->get('unterweisung'), $day)));
        }
        $reports = $this->reportMissions($ids)->groupBy('user_id')->map(fn ($rows) => $rows->max('alarmed_at'));
        // Last time under CSA: a CSA-Übung, any Nachweis worn with CSA, or a CSA-Trupp of the Überwachung
        $csaTrainings = Training::whereIn('user_id', $ids)->where(fn ($q) => $q->where('kind', 'csa_uebung')->orWhere('csa', true))
            ->selectRaw('user_id, max(done_on) as last')->groupBy('user_id')->pluck('last', 'user_id');
        $csaTeams = DB::table('as_team_members')->join('as_teams', 'as_teams.id', '=', 'as_team_members.team_id')
            ->whereIn('as_team_members.user_id', $ids)->where('as_teams.csa', true)->whereNotNull('as_teams.started_at')
            ->selectRaw('as_team_members.user_id, max(as_teams.started_at) as last')->groupBy('as_team_members.user_id')->pluck('last', 'user_id');

        return $wearers->mapWithKeys(fn (Wearer $w) => [$w->user_id => $this->evaluate($w, $own[$w->user_id] ?? collect(), collect([$teams[$w->user_id] ?? null, $reports[$w->user_id] ?? null])->filter()->max(), $lq[$w->user_id] ?? [],
            collect([$csaTrainings[$w->user_id] ?? null, $csaTeams[$w->user_id] ?? null])->filter()->max())]);
    }

    /** @return array{wearer: Wearer, ready: bool, soon: bool, reasons: list<string>, items: array<string, array{ok: bool, until: ?Carbon, last: ?Carbon, source: ?string}>} */
    public function forWearer(Wearer $wearer): array
    {
        return $this->forWearers(collect([$wearer]))->first();
    }

    /**
     * Tragezeit of the year: the Trupps of the Überwachung (Einsatz or Übung, CSA-Trupps under CSA) and the
     * Nachweise with minutes (Belastungsübung, Einsatz/Übung, CSA-Übung or anything worn with CSA).
     *
     * @return array{year: int, minutes: int, teams: int, by: array<string, int>}
     */
    public function wearTime(User $user, ?int $year = null): array
    {
        $year ??= (int) now('Europe/Berlin')->year;
        $by = array_fill_keys(array_keys(self::WEAR_KINDS), 0);
        $teams = Team::with('operation')->whereNotNull('started_at')->whereYear('started_at', $year)
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->get();
        foreach ($teams as $team) {
            $by[$team->csa ? 'csa' : ($team->operation?->kind === 'einsatz' ? 'einsatz' : 'uebung')] += (int) $team->minutes();
        }
        foreach (Training::where('user_id', $user->id)->whereYear('done_on', $year)->whereNotNull('minutes')->get() as $t) {
            $by[$t->csa || $t->kind === 'csa_uebung' ? 'csa' : ($t->kind === 'belastungsuebung' ? 'belastungsuebung' : 'uebung')] += (int) $t->minutes;
        }

        return ['year' => $year, 'minutes' => array_sum($by), 'teams' => $teams->count(), 'by' => $by];
    }

    /** @return array<string, int> minutes of the whole Wehr in the year by kind (Trupps count per Geräteträger) */
    public function wearTimeAll(int $year): array
    {
        $by = array_fill_keys(array_keys(self::WEAR_KINDS), 0);
        foreach (Team::with('operation')->withCount('members')->whereNotNull('started_at')->whereYear('started_at', $year)->get() as $team) {
            $by[$team->csa ? 'csa' : ($team->operation?->kind === 'einsatz' ? 'einsatz' : 'uebung')] += (int) $team->minutes() * $team->members_count;
        }
        foreach (Training::whereYear('done_on', $year)->whereNotNull('minutes')->get() as $t) {
            $by[$t->csa || $t->kind === 'csa_uebung' ? 'csa' : ($t->kind === 'belastungsuebung' ? 'belastungsuebung' : 'uebung')] += (int) $t->minutes;
        }

        return $by;
    }

    /** @return array{ready: int, soon: int, not_ready: int, csa_ready: int} the pool in numbers */
    public function poolCounts(): array
    {
        $states = $this->forWearers(Wearer::active()->get());

        return [
            'csa_ready' => $states->filter(fn ($s) => $s['csa']['ready'] ?? false)->count(),
            'ready' => $states->where('ready', true)->where('soon', false)->count(),
            'soon' => $states->where('ready', true)->where('soon', true)->count(),
            'not_ready' => $states->where('ready', false)->count(),
        ];
    }

    /**
     * Daily: each Kamerad gets one mail per deadline that runs out within SOON_DAYS (or has run out),
     * the local Wehrführung its Standort's entries and global Atemschutzwarte the full overview.
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
            // CSA-Träger are also reminded of their CSA-Übung
            $due = collect($state['items'])->only(self::BLOCKING)->merge($state['csa'] ? ['csa_uebung' => $state['csa']['items']['csa_uebung']] : [])
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

            $lines = $due->map(fn ($item, $key) => (self::ITEMS[$key] ?? self::CSA_ITEMS[$key]).($item['until']->lt(self::today()) ? ' abgelaufen am ' : ' läuft ab am ').$item['until']->format('d.m.Y'))->values();
            $digest->push(['name' => $wearer->user->name, 'lines' => $lines, 'location_id' => $wearer->user->getAttribute('ext_location_id')]);
            $this->notify(collect([$wearer->user]), new DeadlineNotification($lines->all()));
        }

        if ($digest->isNotEmpty()) {
            $managers = app(MonitorService::class)->managers();
            app(LocationNotifications::class)->digest(
                $digest,
                fn (array $entry) => $entry['location_id'],
                fn (Collection $entries) => new PoolDigestNotification($entries),
                fn () => $managers,
            );
        }

        return $digest->count();
    }

    private function evaluate(Wearer $wearer, Collection $own, ?string $lastTeam, array $lq, ?string $lastCsa = null): array
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

        $csa = null;
        if ($wearer->is_csa) {
            $csaItems = ['csa' => ['ok' => true, 'until' => null, 'last' => $date($wearer->csa_qualified_on), 'source' => null], 'csa_uebung' => $yearly($date($lastCsa))];
            $csaReasons = [...($ready ? [] : ['Atemschutz nicht einsatzbereit']), ...($csaItems['csa_uebung']['ok'] ? [] : [self::CSA_ITEMS['csa_uebung'].($csaItems['csa_uebung']['until'] ? ' abgelaufen' : ' fehlt')])];
            $csa = ['ready' => $csaReasons === [], 'items' => $csaItems, 'reasons' => $csaReasons,
                'soon' => $csaReasons === [] && $csaItems['csa_uebung']['until']?->lte($soonLimit)];
        }

        return [
            'wearer' => $wearer, 'ready' => $ready, 'items' => $items, 'reasons' => $reasons,
            'soon' => $ready && collect(self::BLOCKING)->contains(fn ($key) => $items[$key]['until']?->lte($soonLimit)),
            'csa' => $csa,
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

    /** @return array<int, string> user id → last Atemschutz-Unterweisung held in the module Unterweisungen */
    private function fromUnterweisungen(array $userIds): array
    {
        if (! app(ModuleRegistry::class)->isActive('unterweisungen')) {
            return [];
        }
        try {
            return DB::table('uw_attendances')->join('uw_sessions', 'uw_sessions.id', '=', 'uw_attendances.session_id')
                ->join('uw_topics', 'uw_topics.id', '=', 'uw_sessions.topic_id')->where('uw_topics.counts_as', 'atemschutz')
                ->whereIn('uw_attendances.user_id', $userIds)->selectRaw('uw_attendances.user_id, max(uw_sessions.held_on) as last')
                ->groupBy('uw_attendances.user_id')->pluck('last', 'user_id')->map(fn ($d) => (string) $d)->all();
        } catch (Throwable $e) {
            report($e);

            return [];
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
                ->get(['lq_records.user_id', 'lq_records.completed_on', 'lq_courses.name']);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $key = stripos($row->name, 'Belastungsübung') !== false ? 'belastungsuebung' : 'agt';
            // Without a date (old Lehrgang entered afterwards) the Lehrgang counts, but gives no date
            $when = (string) $row->completed_on;
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
