<?php

namespace Modules\Unterweisungen\src\Services;

use App\Models\User;
use App\Services\LocationNotifications;
use App\Services\ModuleRegistry;
use App\Support\FileResponse;
use App\Support\SignatureImage;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Notifications\InstructionDueNotification;
use Throwable;

/**
 * Unterweisungen and Einweisungen: kinds with interval and audience, held sessions where every participant
 * signs on the screen, the state per Kamerad (gültig / läuft ab / abgelaufen / fehlt) and reminders.
 */
class InstructionService
{
    public const MODULE = 'unterweisungen';

    public const WARN_DAYS = 30;

    public const STATES = ['ok' => 'gültig', 'soon' => 'läuft bald ab', 'expired' => 'abgelaufen', 'missing' => 'fehlt'];

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function canManage(User $user): bool
    {
        return self::canManageAll($user) || self::isAtemschutzOnlyManager($user);
    }

    public static function canManageAll(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin
                || $user->moduleAccess()->where('module', self::MODULE)->where('role', 'unterweisungsbeauftragter')->exists()
                || app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'gemeindebrandinspektor'));
    }

    public static function isAtemschutzOnlyManager(User $user): bool
    {
        return config('locations.enabled')
            && $user->is_active
            && $user->canUseModule(self::MODULE)
            && $user->canUseModule('atemschutz')
            && ! self::canManageAll($user)
            && app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'atemschutzwart');
    }

    public static function canManageTopic(User $user, Topic $topic): bool
    {
        return self::canManage($user)
            && (! self::isAtemschutzOnlyManager($user) || $topic->counts_as === 'atemschutz');
    }

    public static function canManageSession(User $user, Session $session): bool
    {
        return self::canManage($user) && self::canManageTopic($user, $session->topic);
    }

    /** @return Builder<Topic> */
    public static function topicsForManager(User $user): Builder
    {
        return Topic::query()->when(
            self::isAtemschutzOnlyManager($user),
            fn (Builder $query) => $query->where('counts_as', 'atemschutz'),
        );
    }

    /** @return Builder<Session> */
    public static function sessionsForManager(User $user): Builder
    {
        return Session::query()->when(
            self::isAtemschutzOnlyManager($user),
            fn (Builder $query) => $query->whereHas('topic', fn (Builder $topics) => $topics->where('counts_as', 'atemschutz')),
        );
    }

    /** @return EloquentCollection<int, User> */
    public function managers(): EloquentCollection
    {
        $atemschutzwartIds = app(StandardRoleAssignments::class)->activeGlobalProfileUserIds('atemschutzwart');
        $managers = User::active()->where(function ($query) use ($atemschutzwartIds) {
            $query->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'unterweisungsbeauftragter'))
                ->orWhereIn('id', $atemschutzwartIds);
        })->get()->filter(fn (User $user) => self::canManage($user))->values();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── kinds ───────────────────────────────────────────────────────────────

    public function saveTopic(User $by, ?Topic $topic, array $data): Topic
    {
        $this->assertManager($by);
        if ($topic && ! self::canManageTopic($by, $topic)) {
            throw ValidationException::withMessages(['topic_name' => 'Diese Art darfst du nicht verwalten.']);
        }
        $name = trim((string) ($data['name'] ?? ''));
        $months = filled($data['interval_months'] ?? null) ? (int) $data['interval_months'] : null;
        $errors = array_filter([
            'topic_name' => $name === '' || mb_strlen($name) > 150 ? 'Bitte einen Namen angeben.' : null,
            'topic_kind' => array_key_exists($data['kind'] ?? '', Topic::KINDS) ? null : 'Bitte Unterweisung oder Einweisung wählen.',
            'topic_interval' => $months !== null && ($months < 1 || $months > 120) ? 'Das Intervall liegt zwischen 1 und 120 Monaten (leer = einmalig).' : null,
        ]);
        if (self::isAtemschutzOnlyManager($by) && ($data['counts_as'] ?? null) !== 'atemschutz') {
            $errors['topic_access'] = 'Atemschutzwarte dürfen nur Atemschutz-Unterweisungen verwalten.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $topic ??= new Topic;
        $topic->fill([
            'name' => $name, 'short' => filled($data['short'] ?? null) ? mb_substr(trim($data['short']), 0, 12) : null, 'kind' => $data['kind'], 'interval_months' => $months,
            'vehicle_id' => filled($data['vehicle_id'] ?? null) ? (int) $data['vehicle_id'] : null,
            'counts_as' => array_key_exists($data['counts_as'] ?? '', Topic::COUNTS_AS) ? $data['counts_as'] : null,
            'description' => filled($data['description'] ?? null) ? mb_substr(trim($data['description']), 0, 2000) : null,
            'for_all' => (bool) ($data['for_all'] ?? false), 'group_ids' => array_map('intval', $data['group_ids'] ?? []), 'function_ids' => array_map('intval', $data['function_ids'] ?? []),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $topic;
    }

    public function addPresets(User $by): int
    {
        $this->assertManager($by);
        $added = 0;
        $presets = self::isAtemschutzOnlyManager($by)
            ? array_filter(Topic::PRESETS, fn ($preset) => $preset[5] === 'atemschutz')
            : Topic::PRESETS;
        foreach ($presets as [$name, $short, $kind, $months, $all, $countsAs]) {
            if (! Topic::where('name', $name)->exists()) {
                Topic::create(['name' => $name, 'short' => $short, 'kind' => $kind, 'interval_months' => $months, 'for_all' => $all, 'counts_as' => $countsAs]);
                $added++;
            }
        }

        return $added;
    }

    // ─── sessions ────────────────────────────────────────────────────────────

    /**
     * An Unterweisung was held: topic, day, who instructed, who took part (they sign afterwards, on the
     * tablet or their own phone), documents.
     *
     * @param  list<int>  $userIds
     * @param  list<UploadedFile>  $files
     */
    public function recordSession(User $by, array $data, array $userIds, array $files = []): Session
    {
        $this->assertManager($by);
        $topic = Topic::find((int) ($data['topic_id'] ?? 0));
        if ($topic && ! self::canManageTopic($by, $topic)) {
            throw ValidationException::withMessages(['session_topic' => 'Diese Art darfst du nicht verwalten.']);
        }
        $date = filled($data['held_on'] ?? null) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', $data['held_on']), null, false) : null;
        $users = User::whereIn('id', array_map('intval', $userIds))->get();
        $errors = array_filter([
            'session_topic' => $topic ? null : 'Bitte die Unterweisung wählen.',
            'session_date' => $date && $date->lte(self::today()) ? null : 'Bitte das Datum angeben (nicht in der Zukunft).',
            'session_users' => $users->isEmpty() ? 'Bitte mindestens einen Teilnehmer auswählen.' : null,
            'session_instructor' => trim((string) ($data['instructor_name'] ?? '')) === '' ? 'Bitte angeben, wer unterwiesen hat.' : null,
        ]);
        foreach ($files as $file) {
            if ($file->getSize() > 10 * 1024 * 1024) {
                $errors['session_files'] = '„'.$file->getClientOriginalName().'“ ist zu groß (höchstens 10 MB).';
            } elseif (! in_array(mb_strtolower($file->getClientOriginalExtension()), FileResponse::allowedExtensions(), true)) {
                $errors['session_files'] = '„'.$file->getClientOriginalName().'“: bitte PDF, Foto oder Office-Datei.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $topic, $date, $data, $users, $files) {
            $session = Session::create(['topic_id' => $topic->id, 'held_on' => $date, 'instructor_name' => mb_substr(trim($data['instructor_name']), 0, 150),
                'instructor_id' => filled($data['instructor_id'] ?? null) ? (int) $data['instructor_id'] : null,
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : null, 'created_by' => $by->id]);
            foreach ($users as $user) {
                $session->attendances()->create(['user_id' => $user->id]);
            }
            foreach ($files as $file) {
                $session->files()->create(['filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(), 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]);
            }

            return $session;
        });
    }

    /** The participant signs: passed around on the tablet (Beauftragter) or on their own phone */
    public function sign(User $by, Attendance $attendance, string $dataUrl): void
    {
        $manager = self::canManage($by);
        if ($manager && ! self::canManageSession($by, $attendance->session) && $attendance->user_id !== $by->id) {
            throw ValidationException::withMessages(['signature' => 'Diese Unterweisung darfst du nicht verwalten.']);
        }
        if (! $manager && $attendance->user_id !== $by->id) {
            throw ValidationException::withMessages(['signature' => 'Unterschreiben kann nur der Teilnehmer selbst.']);
        }
        if ($attendance->signed_at) {
            throw ValidationException::withMessages(['signature' => 'Schon unterschrieben.']);
        }
        $attendance->update(['signature' => SignatureImage::fromDataUrl($dataUrl), 'signed_at' => now()]);
    }

    /** Kameraden who were present at a Kalender event – to take over as participants */
    public function fromEvent(User $by, int $eventId): array
    {
        $this->assertManager($by);
        abort_if(self::isAtemschutzOnlyManager($by), 403);
        if (! app(ModuleRegistry::class)->isActive('kalender')) {
            return [];
        }

        return DB::table('kal_responses')->where('event_id', $eventId)->where('attended', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    // ─── state ───────────────────────────────────────────────────────────────

    /** @return array<int, array<int, Carbon>> user id → topic id → last day held */
    public function lastDates(array $userIds): array
    {
        $rows = DB::table('uw_attendances')->join('uw_sessions', 'uw_sessions.id', '=', 'uw_attendances.session_id')
            ->whereIn('uw_attendances.user_id', $userIds)->selectRaw('uw_attendances.user_id, uw_sessions.topic_id, max(uw_sessions.held_on) as last')
            ->groupBy('uw_attendances.user_id', 'uw_sessions.topic_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->user_id][$r->topic_id] = Carbon::parse($r->last);
        }

        return $out;
    }

    /** @return array{state: string, last: ?Carbon, until: ?Carbon} */
    public static function stateOf(Topic $topic, ?Carbon $last): array
    {
        $until = $last && $topic->interval_months ? $last->copy()->addMonths($topic->interval_months) : null;
        $today = self::today();
        $state = match (true) {
            $last === null => 'missing',
            $until === null => 'ok',
            $until->lt($today) => 'expired',
            $until->lte($today->copy()->addDays(self::WARN_DAYS)) => 'soon',
            default => 'ok',
        };

        return compact('state', 'last', 'until');
    }

    /** @return Collection<int, array{topic: Topic, required: bool, state: string, last: ?Carbon, until: ?Carbon}> the Kamerad's Unterweisungen: required ones and every one attended */
    public function forUser(User $user): Collection
    {
        $last = $this->lastDates([$user->id])[$user->id] ?? [];

        return Topic::where('is_active', true)->orderBy('name')->get()
            ->map(fn (Topic $t) => ['topic' => $t, 'required' => $t->requiredFor($user), ...self::stateOf($t, $last[$t->id] ?? null)])
            ->filter(fn ($row) => $row['required'] || $row['last'])->values();
    }

    /**
     * @return array{
     *     users: EloquentCollection<int, User>,
     *     topics: EloquentCollection<int, Topic>,
     *     cells: array<int, array<int, array{required: bool, state: string, last: ?Carbon, until: ?Carbon}>>
     * } active Kameraden × active kinds
     */
    public function matrix(?int $groupId = null, ?User $manager = null): array
    {
        /** @var EloquentCollection<int, User> $users */
        $users = User::active()->with(['groups', 'functions'])->when($groupId, fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereKey($groupId)))->orderBy('name')->get();
        /** @var EloquentCollection<int, Topic> $topics */
        $topics = ($manager ? self::topicsForManager($manager) : Topic::query())->where('is_active', true)->orderBy('name')->get();
        $last = $this->lastDates($users->pluck('id')->all());
        $cells = [];
        foreach ($users as $u) {
            foreach ($topics as $t) {
                $cells[$u->id][$t->id] = ['required' => $t->requiredFor($u), ...self::stateOf($t, $last[$u->id][$t->id] ?? null)];
            }
        }

        return compact('users', 'topics', 'cells');
    }

    /** Daily: a required Unterweisung runs out within WARN_DAYS or has run out – the Kamerad once per due date, the Beauftragte an overview */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }
        $digest = [];
        $remindedCount = 0;
        $m = $this->matrix();
        foreach ($m['users'] as $user) {
            $lines = [];
            foreach ($m['topics'] as $topic) {
                $cell = $m['cells'][$user->id][$topic->id];
                if (! $cell['required'] || ! in_array($cell['state'], ['soon', 'expired'], true)) {
                    continue;
                }
                // Claim on the latest attendance: one reminder per due date
                $attendance = Attendance::where('user_id', $user->id)->whereHas('session', fn ($q) => $q->where('topic_id', $topic->id))
                    ->get()->sortByDesc(fn ($a) => $a->session->held_on)->first();
                if (! $attendance || $attendance->reminded_on?->equalTo($cell['until'])) {
                    continue;
                }
                $attendance->update(['reminded_on' => $cell['until']]);
                $topicLine = $topic->label().($cell['state'] === 'expired' ? ' abgelaufen am ' : ' läuft ab am ').$cell['until']->format('d.m.Y');
                $lines[] = $topicLine;
                $digest[] = ['topic' => $topic, 'location_id' => $user->getAttribute('ext_location_id'), 'line' => $user->name.': '.$topicLine];
            }
            if ($lines) {
                $this->notify(collect([$user]), new InstructionDueNotification($lines));
                $remindedCount++;
            }
        }
        if ($digest !== []) {
            $managers = $this->managers();
            app(LocationNotifications::class)->digest(
                collect($digest),
                fn (array $entry) => $entry['location_id'],
                fn (Collection $entries) => new InstructionDueNotification($entries->pluck('line')->all(), true),
                fn (array $entry) => $managers->filter(fn (User $manager) => self::canManageTopic($manager, $entry['topic'])),
            );
        }

        return $remindedCount;
    }

    // ─── for other modules ───────────────────────────────────────────────────

    /** Fahrtenbuch: a vehicle with an Einweisung the driver doesn't have (valid) */
    public function vehicleWarning(User $driver, int $vehicleId): ?string
    {
        $topics = Topic::where('is_active', true)->where('kind', 'einweisung')->where('vehicle_id', $vehicleId)->get();
        if ($topics->isEmpty()) {
            return null;
        }
        $last = $this->lastDates([$driver->id])[$driver->id] ?? [];
        $missing = $topics->filter(fn (Topic $t) => in_array(self::stateOf($t, $last[$t->id] ?? null)['state'], ['missing', 'expired'], true));

        return $missing->isEmpty() ? null : $driver->name.' ist nicht (mehr) eingewiesen: '.$missing->pluck('name')->implode(', ').'.';
    }

    /** @return array<int, list<string>> the Kürzel of what is valid, per Kamerad */
    public function badges(array $userIds): array
    {
        $topics = Topic::where('is_active', true)->whereNotNull('short')->get()->keyBy('id');
        $out = [];
        foreach ($this->lastDates($userIds) as $userId => $dates) {
            foreach ($dates as $topicId => $last) {
                if (($t = $topics[$topicId] ?? null) && in_array(self::stateOf($t, $last)['state'], ['ok', 'soon'], true)) {
                    $out[$userId][] = $t->short;
                }
            }
        }

        return $out;
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['topic_name' => 'Das dürfen nur Unterweisungsbeauftragte und Admins.']);
        }
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
