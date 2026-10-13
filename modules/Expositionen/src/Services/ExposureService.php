<?php

namespace Modules\Expositionen\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Expositionen\src\Models\Dismissal;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Models\ExposureEvent;
use Modules\Expositionen\src\Notifications\ExposureProposedNotification;
use Modules\Expositionen\src\Notifications\ExposureRecordedNotification;
use Throwable;

/**
 * Gefahrstoff-Expositionen: every Kamerad records their own, the Einsatzleiter proposes one for the crew of
 * an Einsatz (each confirms it), the Expositionsbeauftragte keeps the list for the DGUV-ZED. Kept 40 years.
 * Mails never contain what was recorded – only that there is something to look at.
 */
class ExposureService
{
    public const MODULE = 'expositionen';

    /** The start page asks after an Einsatz of these categories */
    public const ASK_CATEGORIES = ['brand_klein', 'brand_mittel', 'brand_gross'];

    public const ASK_DAYS = 14;

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'expositionsbeauftragter'));
    }

    /** Einsatzleiter and Wehrführung propose exposures for the crew of an Einsatz */
    public static function canPropose(User $user): bool
    {
        return self::canManage($user) || (app(ModuleRegistry::class)->isActive('einsatzbericht') && ReportService::canLead($user) && $user->canUseModule(self::MODULE));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'expositionsbeauftragter'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    /** A Kamerad records their own exposure, or the Beauftragte one for a Kamerad */
    public function record(User $by, User $member, array $data, ?Report $report = null): Exposure
    {
        if (! $by->is($member) && ! self::canManage($by)) {
            throw ValidationException::withMessages(['exposure' => 'Für andere erfassen nur Expositionsbeauftragte – oder der Einsatzleiter über den Einsatzbericht.']);
        }
        $clean = $this->validated($data);
        $exposure = DB::transaction(function () use ($by, $member, $clean, $report) {
            $exposure = Exposure::create([...$clean, 'user_id' => $member->id, 'person_name' => $member->name, 'report_id' => $report?->id,
                'status' => 'recorded', 'recorded_by' => $by->id, 'retain_until' => $clean['occurred_on']->copy()->addYears(Exposure::RETAIN_YEARS)]);
            $this->log($exposure, $by, 'recorded');

            return $exposure;
        });
        $this->notify($this->managers()->reject(fn (User $u) => $u->is($by)), new ExposureRecordedNotification($exposure));

        return $exposure;
    }

    /**
     * The Einsatzleiter proposes one exposure for several Kameraden of the crew: each gets it as a proposal
     * to confirm or correct. Who wore Atemschutz in the Einsatzbericht has it as protection already.
     *
     * @param  list<int>  $userIds
     */
    public function proposeForCrew(User $by, Report $report, array $userIds, array $data): int
    {
        if (! self::canPropose($by)) {
            throw ValidationException::withMessages(['exposure' => 'Das dürfen Einsatzleiter, Wehrführung und Expositionsbeauftragte.']);
        }
        $crew = $report->crew()->with('user')->whereIn('user_id', $userIds)->get();
        if ($crew->isEmpty()) {
            throw ValidationException::withMessages(['crew' => 'Bitte mindestens eine Einsatzkraft auswählen.']);
        }
        $clean = $this->validated($data);
        $known = Exposure::where('report_id', $report->id)->pluck('user_id')->all();

        $created = DB::transaction(fn () => $crew->reject(fn ($c) => in_array($c->user_id, $known, true))->map(function ($c) use ($by, $report, $clean) {
            $protection = array_values(array_unique([...($clean['protection'] ?? []), ...($c->breathing_apparatus ? ['atemschutz'] : [])]));
            $exposure = Exposure::create([...$clean, 'protection' => array_values(array_diff($protection, count($protection) > 1 ? ['keine'] : [])),
                'user_id' => $c->user_id, 'person_name' => $c->user->name, 'report_id' => $report->id, 'status' => 'draft', 'recorded_by' => $by->id,
                'retain_until' => $clean['occurred_on']->copy()->addYears(Exposure::RETAIN_YEARS)]);
            $this->log($exposure, $by, 'proposed');

            return $exposure;
        }));

        $created->each(fn (Exposure $e) => $e->user && ! $e->user->is($by) ? $this->notify(collect([$e->user]), new ExposureProposedNotification($e)) : null);

        return $created->count();
    }

    /** Corrects an exposure: the Kamerad as long as it isn't reported (confirming a proposal), the Beauftragte always */
    public function update(User $by, Exposure $exposure, array $data): Exposure
    {
        $own = $exposure->user_id === $by->id;
        if (! self::canManage($by) && ! ($own && $exposure->status !== 'reported')) {
            throw ValidationException::withMessages(['exposure' => 'Eine gemeldete Exposition ändert nur der Expositionsbeauftragte.']);
        }
        $clean = $this->validated($data);
        $confirming = $exposure->status === 'draft' && $own;
        DB::transaction(function () use ($by, $exposure, $clean, $confirming) {
            $exposure->update([...$clean, 'retain_until' => $clean['occurred_on']->copy()->addYears(Exposure::RETAIN_YEARS),
                'status' => $confirming ? 'recorded' : $exposure->status]);
            $this->log($exposure, $by, $confirming ? 'confirmed' : 'changed');
        });
        if ($confirming) {
            $this->notify($this->managers()->reject(fn (User $u) => $u->is($by)), new ExposureRecordedNotification($exposure));
        }

        return $exposure;
    }

    /** A proposal that doesn't apply (e.g. was not at the fire) is withdrawn; recorded ones stay 40 years */
    public function discard(User $by, Exposure $exposure): void
    {
        if ($exposure->status !== 'draft' || (! self::canManage($by) && $exposure->user_id !== $by->id && $exposure->recorded_by !== $by->id)) {
            throw ValidationException::withMessages(['exposure' => 'Nur ein Vorschlag lässt sich verwerfen – eine erfasste Exposition wird 40 Jahre aufbewahrt.']);
        }
        $exposure->delete();
    }

    /** @param  list<int>  $ids */
    public function markReported(User $by, array $ids, ?string $on, ?string $reference): int
    {
        if (! self::canManage($by)) {
            throw ValidationException::withMessages(['exposure' => 'Das dürfen nur Expositionsbeauftragte und Admins.']);
        }
        $date = filled($on) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', $on), null, false) : null;
        if (! $date || $date->gt(self::today())) {
            throw ValidationException::withMessages(['reported_on' => 'Bitte das Datum der Meldung angeben.']);
        }
        $exposures = Exposure::whereIn('id', $ids)->where('status', 'recorded')->get();
        foreach ($exposures as $e) {
            $e->update(['status' => 'reported', 'reported_on' => $date, 'zed_reference' => filled($reference) ? mb_substr(trim($reference), 0, 100) : null]);
            $this->log($e, $by, 'reported');
        }

        return $exposures->count();
    }

    /** Recent Brand-Einsätze of the Kamerad without an exposure or "keine": the start page asks */
    public function openPrompts(User $user): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return Report::whereIn('category', self::ASK_CATEGORIES)->where('status', '!=', 'draft')
            ->where('alarmed_at', '>=', now()->subDays(self::ASK_DAYS))
            ->whereHas('crew', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotIn('id', Exposure::where('user_id', $user->id)->whereNotNull('report_id')->select('report_id'))
            ->whereNotIn('id', Dismissal::where('user_id', $user->id)->select('report_id'))
            ->orderByDesc('alarmed_at')->get();
    }

    public function dismiss(User $user, Report $report): void
    {
        Dismissal::firstOrCreate(['user_id' => $user->id, 'report_id' => $report->id]);
    }

    /** The occasion text for an Einsatz: "Einsatz 2027/012 · FEU 2" */
    public static function occasionFor(Report $report): string
    {
        return mb_substr('Einsatz '.$report->number.($report->keyword ? ' · '.$report->keyword : ''), 0, 200);
    }

    /** CSV for the DGUV-ZED (entering or importing there) and for the Träger */
    public function csv(Collection $exposures): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Geburtsdatum', 'Datum', 'Anlass', 'Tätigkeit', 'Gefahrstoffe', 'Dauer (min)', 'Aufnahmeweg', 'Schutzmaßnahmen', 'Bemerkung', 'Status', 'Gemeldet am', 'ZED-Referenz', 'Aufbewahren bis'], ';');
        foreach ($exposures as $e) {
            fputcsv($out, [$e->person_name, $e->user?->birth_date?->format('d.m.Y'), $e->occurred_on->format('d.m.Y'), $e->occasion, $e->activityLabel(), $e->substanceList(),
                $e->duration_minutes, $e->routeList(), $e->protectionList(), $e->notes, $e->statusLabel(), $e->reported_on?->format('d.m.Y'), $e->zed_reference,
                $e->retain_until->format('d.m.Y')], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** @return array<string, mixed> */
    private function validated(array $data): array
    {
        $errors = [];
        $date = filled($data['occurred_on'] ?? null) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', (string) $data['occurred_on']), null, false) : null;
        if (! $date || $date->gt(self::today()) || $date->year < 1950) {
            $errors['occurred_on'] = 'Bitte das Datum angeben (nicht in der Zukunft).';
        }
        $occasion = trim((string) ($data['occasion'] ?? ''));
        if ($occasion === '' || mb_strlen($occasion) > 200) {
            $errors['occasion'] = 'Bitte den Anlass angeben, z. B. Einsatz oder Übung.';
        }
        if (! array_key_exists($data['activity'] ?? '', Exposure::ACTIVITIES)) {
            $errors['activity'] = 'Bitte die Tätigkeit wählen.';
        }
        $substances = collect($data['substances'] ?? [])->merge(preg_split('/[,;\n]+/', (string) ($data['other_substances'] ?? '')) ?: [])
            ->map(fn ($s) => mb_substr(trim((string) $s), 0, 150))->filter()->unique()->values()->all();
        if ($substances === []) {
            $errors['substances'] = 'Bitte mindestens einen Gefahrstoff angeben – „Unbekannter Stoff“, wenn unklar.';
        }
        $minutes = filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null;
        if ($minutes !== null && ($minutes < 1 || $minutes > 1440)) {
            $errors['duration_minutes'] = 'Die Dauer liegt zwischen 1 und 1440 Minuten.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'occurred_on' => $date, 'occasion' => $occasion,
            'activity' => ['kind' => $data['activity'], 'text' => filled($data['activity_text'] ?? null) ? mb_substr(trim($data['activity_text']), 0, 300) : null],
            'substances' => $substances, 'duration_minutes' => $minutes,
            'routes' => array_values(array_intersect(array_keys(Exposure::ROUTES), (array) ($data['routes'] ?? []))),
            'protection' => array_values(array_intersect(array_keys(Exposure::PROTECTION), (array) ($data['protection'] ?? []))),
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 1000) : null,
        ];
    }

    private function log(Exposure $exposure, User $by, string $action): void
    {
        ExposureEvent::create(['exposure_id' => $exposure->id, 'user_id' => $by->id, 'action' => $action, 'created_at' => now()]);
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
