<?php

namespace Modules\Expositionen\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\Csv;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationContext;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (! $user->is_active || ! $user->canUseModule(self::MODULE)) {
            return false;
        }
        if (! config('locations.enabled')) {
            return $user->is_admin || $user->hasModuleRole(self::MODULE, 'expositionsbeauftragter');
        }
        if (self::isGlobalManager($user)) {
            return true;
        }
        if (! Schema::hasTable('x_locations')) {
            return false;
        }

        return app(StandardRoleAssignments::class)->locationIds($user, self::MODULE, ['wehrführung']) !== [];
    }

    public static function canManageAt(User $user, ?int $locationId): bool
    {
        if (! config('locations.enabled')) {
            return self::canManage($user);
        }
        if (! $user->is_active || ! $user->canUseModule(self::MODULE)) {
            return false;
        }
        if (self::isGlobalManager($user)) {
            return true;
        }

        return Schema::hasTable('x_locations') && $locationId !== null
            && app(StandardRoleAssignments::class)->hasModuleRoleAt($user, self::MODULE, 'wehrführung', $locationId);
    }

    /** Crew proposals contain health data and are therefore limited to the report's local leadership. */
    public static function canPropose(User $user, Report $report): bool
    {
        if (! config('locations.enabled')) {
            return self::canManage($user)
                || (app(ModuleRegistry::class)->isActive('einsatzbericht')
                    && ReportService::canLead($user) && $user->canUseModule(self::MODULE));
        }

        $locationId = $report->ext_location_id === null ? null : (int) $report->ext_location_id;

        return Schema::hasTable('x_locations') && $locationId !== null
            && DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()
            && self::canManageAt($user, $locationId);
    }

    public static function canView(User $user, Exposure $exposure): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($exposure->user_id === $user->id || self::canManageAt($user, $exposure->ext_location_id === null ? null : (int) $exposure->ext_location_id));
    }

    private static function isGlobalManager(User $user): bool
    {
        return DB::table('users')->where('id', $user->id)->where('is_active', true)->where('is_admin', true)->exists()
            || app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'gemeindebrandinspektor');
    }

    /** @return list<int> */
    public static function managedLocationIds(User $user): array
    {
        if (self::isGlobalManager($user)) {
            return config('locations.enabled') && Schema::hasTable('x_locations')
                ? Location::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return config('locations.enabled') && Schema::hasTable('x_locations')
            ? app(StandardRoleAssignments::class)->locationIds($user, self::MODULE, ['wehrführung'])
            : [];
    }

    /** @return Builder<Exposure> */
    public static function managedExposures(User $user): Builder
    {
        $query = Exposure::query();
        if (! config('locations.enabled')) {
            return self::canManage($user) ? $query : $query->whereRaw('1 = 0');
        }
        if (self::isGlobalManager($user)) {
            return $query;
        }
        if (! self::canManage($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('ext_location_id', self::managedLocationIds($user));
    }

    /** @return EloquentCollection<int, Location> */
    public static function managedLocations(User $user): EloquentCollection
    {
        if (! config('locations.enabled') || ! Schema::hasTable('x_locations')) {
            return new EloquentCollection;
        }

        $ids = self::managedLocationIds($user);

        return Location::query()->active()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('name')->get();
    }

    public static function manageableMembers(User $user, ?int $locationId): EloquentCollection
    {
        $members = User::withoutGlobalScopes()->active()->orderBy('name');
        if (! config('locations.enabled')) {
            return self::canManage($user) ? $members->get(['id', 'name']) : $members->whereRaw('1 = 0')->get(['id', 'name']);
        }
        if (! Schema::hasTable('x_locations') || ! Schema::hasTable('x_location_user')
            || $locationId === null || ! DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()
            || (! self::isGlobalManager($user) && ! in_array($locationId, self::managedLocationIds($user), true))) {
            return $members->whereRaw('1 = 0')->get(['id', 'name']);
        }

        $members->where(fn ($query) => $query->where('ext_location_id', $locationId)
            ->orWhereExists(fn ($membership) => $membership->selectRaw('1')->from('x_location_user')
                ->whereColumn('x_location_user.user_id', 'users.id')->where('location_id', $locationId)));

        return $members->get(['id', 'name']);
    }

    public static function canViewCertificate(User $by, User $member): bool
    {
        if ($by->is($member) || self::isGlobalManager($by)) {
            return true;
        }

        return self::managedExposures($by)->where('user_id', $member->id)->where('status', '!=', 'draft')->exists();
    }

    public static function certificateExposures(User $by, User $member): EloquentCollection
    {
        $query = self::isGlobalManager($by)
            ? Exposure::query()
            : ($by->is($member) ? Exposure::query() : self::managedExposures($by));

        return $query->where('user_id', $member->id)->where('status', '!=', 'draft')->orderBy('occurred_on')->get();
    }

    public function managers(?int $locationId): EloquentCollection
    {
        if (! config('locations.enabled')) {
            return User::withoutGlobalScopes()->active()->where(fn ($query) => $query->where('is_admin', true)
                ->orWhereHas('moduleAccess', fn ($query) => $query->where('module', self::MODULE)->where('role', 'expositionsbeauftragter')))->get();
        }

        $ids = User::withoutGlobalScopes()->active()->where('is_admin', true)->pluck('id')->all();
        if (Schema::hasTable('x_standard_role_assignments')) {
            $activeAssignments = DB::table('x_standard_role_assignments')->whereNull('revoked_at')
                ->where('granted_at', '<=', now())->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            $ids = [
                ...$ids,
                ...$activeAssignments->clone()->where('profile', 'gemeindebrandinspektor')
                    ->whereNull('location_id')->whereNull('group_id')->pluck('user_id')->all(),
            ];
            if ($locationId !== null) {
                $ids = [...$ids, ...$activeAssignments->clone()->where('profile', 'wehrfuehrung')
                    ->where('location_id', $locationId)->whereNull('group_id')->pluck('user_id')->all()];
            }
        }

        return User::withoutGlobalScopes()->active()->whereIn('id', array_unique($ids))->get()
            ->filter(fn (User $user) => self::canManageAt($user, $locationId))->values();
    }

    private function locationForRecord(User $by, User $member, array $data, ?Report $report): ?int
    {
        if (! config('locations.enabled')) {
            return null;
        }
        if (! Schema::hasTable('x_locations')) {
            throw ValidationException::withMessages(['ext_location_id' => 'Der Standortschutz ist noch nicht verfügbar.']);
        }

        $contextLocationId = app(LocationContext::class)->forUser($by)['current'];
        $requestedId = filter_var($data['ext_location_id'] ?? $report->ext_location_id ?? $contextLocationId, FILTER_VALIDATE_INT);
        $reportLocationId = $report?->ext_location_id === null ? null : (int) $report->ext_location_id;
        if ($reportLocationId !== null && $requestedId !== $reportLocationId) {
            throw ValidationException::withMessages(['ext_location_id' => 'Die Exposition muss dem Standort des Einsatzberichts zugeordnet bleiben.']);
        }

        if (! is_int($requestedId) || ! DB::table('x_locations')->where('id', $requestedId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['ext_location_id' => 'Bitte einen aktiven Standort auswählen.']);
        }

        $memberBelongsToLocation = DB::table('users')->where('id', $member->id)->where('is_active', true)
            ->where(fn ($query) => $query->where('ext_location_id', $requestedId)
                ->orWhereExists(fn ($membership) => $membership->selectRaw('1')->from('x_location_user')
                    ->whereColumn('x_location_user.user_id', 'users.id')->where('location_id', $requestedId)))
            ->exists();
        if ($by->is($member)) {
            $allowed = $memberBelongsToLocation;
            if ($report && ! $report->crew()->where('user_id', $member->id)->exists()) {
                $allowed = false;
            }
        } else {
            $allowed = $memberBelongsToLocation && self::canManageAt($by, $requestedId);
        }

        if (! $allowed) {
            throw ValidationException::withMessages(['ext_location_id' => 'Du darfst für diesen Standort keine Exposition erfassen.']);
        }

        return $requestedId;
    }

    private function locationForReport(User $by, Report $report): ?int
    {
        if (! config('locations.enabled')) {
            return null;
        }
        if (! Schema::hasTable('x_locations') || $report->ext_location_id === null
            || ! DB::table('x_locations')->where('id', $report->ext_location_id)->where('is_active', true)->exists()
            || ! self::canManageAt($by, (int) $report->ext_location_id)) {
            throw ValidationException::withMessages(['exposure' => 'Für Crew-Vorschläge braucht es einen aktiven, deinem Zuständigkeitsbereich zugeordneten Einsatzstandort.']);
        }

        return (int) $report->ext_location_id;
    }

    /** A Kamerad records their own exposure, or the Beauftragte one for a Kamerad */
    public function record(User $by, User $member, array $data, ?Report $report = null): Exposure
    {
        if (! $by->is_active || ! $member->is_active || ! $by->canUseModule(self::MODULE)
            || (! config('locations.enabled') && ! $by->is($member) && ! self::canManage($by))) {
            throw ValidationException::withMessages(['exposure' => 'Für eine Exposition braucht es ein aktives Mitglied mit Modulzugang.']);
        }
        $clean = $this->validated($data);
        $locationId = $this->locationForRecord($by, $member, $data, $report);
        $exposure = DB::transaction(function () use ($by, $member, $clean, $report, $locationId) {
            $exposure = Exposure::create([...$clean, 'ext_location_id' => $locationId, 'user_id' => $member->id, 'person_name' => $member->name, 'report_id' => $report?->id,
                'status' => 'recorded', 'recorded_by' => $by->id, 'retain_until' => $clean['occurred_on']->copy()->addYears(Exposure::RETAIN_YEARS)]);
            $this->log($exposure, $by, 'recorded');

            return $exposure;
        });
        $this->notify($this->managers($locationId)->reject(fn (User $u) => $u->is($by)), new ExposureRecordedNotification($exposure));

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
        if (! self::canPropose($by, $report)) {
            throw ValidationException::withMessages(['exposure' => 'Das dürfen GBI/SBI und die zuständige Wehrführung.']);
        }
        $crew = $report->crew()->with('user')->whereIn('user_id', $userIds)->get();
        if ($crew->isEmpty()) {
            throw ValidationException::withMessages(['crew' => 'Bitte mindestens eine Einsatzkraft auswählen.']);
        }
        $clean = $this->validated($data);
        $locationId = $this->locationForReport($by, $report);
        $known = Exposure::where('report_id', $report->id)->pluck('user_id')->all();

        $created = DB::transaction(fn () => $crew->reject(fn ($c) => in_array($c->user_id, $known, true))->map(function ($c) use ($by, $report, $clean, $locationId) {
            $protection = array_values(array_unique([...($clean['protection'] ?? []), ...($c->breathing_apparatus ? ['atemschutz'] : [])]));
            $exposure = Exposure::create([...$clean, 'protection' => array_values(array_diff($protection, count($protection) > 1 ? ['keine'] : [])),
                'ext_location_id' => $locationId, 'user_id' => $c->user_id, 'person_name' => $c->user->name, 'report_id' => $report->id, 'status' => 'draft', 'recorded_by' => $by->id,
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
        if (! self::canManageAt($by, $exposure->ext_location_id === null ? null : (int) $exposure->ext_location_id)
            && ! ($own && $exposure->status !== 'reported')) {
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
            $this->notify($this->managers($exposure->ext_location_id === null ? null : (int) $exposure->ext_location_id)
                ->reject(fn (User $u) => $u->is($by)), new ExposureRecordedNotification($exposure));
        }

        return $exposure;
    }

    /** A proposal that doesn't apply (e.g. was not at the fire) is withdrawn; recorded ones stay 40 years */
    public function discard(User $by, Exposure $exposure): void
    {
        if ($exposure->status !== 'draft' || (! self::canManageAt($by, $exposure->ext_location_id === null ? null : (int) $exposure->ext_location_id)
            && $exposure->user_id !== $by->id)) {
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
        $uniqueIds = array_values(array_unique($ids));
        $exposures = self::managedExposures($by)->whereIn('id', $uniqueIds)->where('status', 'recorded')->get();
        if ($exposures->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages(['selected' => 'Mindestens eine ausgewählte Exposition gehört nicht zu deinem Zuständigkeitsbereich oder ist nicht mehr offen.']);
        }
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
        Csv::put($out, ['Name', 'Geburtsdatum', 'Datum', 'Anlass', 'Tätigkeit', 'Gefahrstoffe', 'Dauer (min)', 'Aufnahmeweg', 'Schutzmaßnahmen', 'Bemerkung', 'Status', 'Gemeldet am', 'ZED-Referenz', 'Aufbewahren bis'], ';');
        foreach ($exposures as $e) {
            Csv::put($out, [$e->person_name, $e->user?->birth_date?->format('d.m.Y'), $e->occurred_on->format('d.m.Y'), $e->occasion, $e->activityLabel(), $e->substanceList(),
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
