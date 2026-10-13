<?php

namespace Modules\Personalverwaltung\src\Services;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Support\Audience;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Throwable;

/**
 * Personalverwaltung: who may see whose file (Personalverwalter, optionally only some Abteilungen),
 * the career that writes itself from every change of Dienstgrad, Abteilung, Funktion and status,
 * Ehrungen with the ones due by Dienstjahre, and the Dienstbeteiligung from the Kalender attendance.
 */
class PersonnelService
{
    public const MODULE = 'personalverwaltung';

    /** Kalender types that count as Dienst for the Dienstbeteiligung by default */
    public const DUTY_TYPES = ['uebung', 'dienst', 'arbeitsdienst'];

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    // ─── access ──────────────────────────────────────────────────────────────

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'personalverwalter'));
    }

    /** @return list<int> the Abteilungen a Personalverwalter is limited to; empty = all */
    public static function scopeOf(User $user): array
    {
        if ($user->is_admin) {
            return [];
        }

        return DB::table('pm_scopes')->where('user_id', $user->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The Kameraden whose file $manager may open */
    public static function visibleMembers(User $manager): Builder
    {
        $scope = self::scopeOf($manager);

        return User::query()->when($scope !== [], fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $scope)));
    }

    public static function mayView(User $manager, User $member): bool
    {
        return self::canManage($manager) && self::visibleMembers($manager)->whereKey($member->id)->exists();
    }

    /** Admins only: limit a Personalverwalter to Abteilungen (empty = all) */
    public function setScope(User $by, User $manager, array $groupIds): void
    {
        if (! $by->is_admin) {
            throw ValidationException::withMessages(['scope' => 'Das dürfen nur Admins.']);
        }
        DB::transaction(function () use ($manager, $groupIds) {
            DB::table('pm_scopes')->where('user_id', $manager->id)->delete();
            $ids = Group::whereIn('id', array_map('intval', $groupIds))->pluck('id');
            DB::table('pm_scopes')->insert($ids->map(fn ($id) => ['user_id' => $manager->id, 'group_id' => $id])->all());
        });
    }

    // ─── career ──────────────────────────────────────────────────────────────

    /**
     * Brings the career up to date with the member data: a new Dienstgrad, status, Abteilung or
     * Funktion opens a stage, a former one is closed. The very first entries take the known dates
     * (Dienstgrad since, Eintritt); later changes count from today. Returns the number of changes.
     */
    public function syncHistory(User $user): int
    {
        $user->loadMissing(['groups', 'functions.parent']);
        $today = self::today();
        $changes = 0;

        $single = function (string $kind, ?string $label, ?Carbon $firstSince) use ($user, $today, &$changes) {
            $open = HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->whereNull('until')->latest('id')->first();
            if ($open?->label === $label) {
                return;
            }
            $first = ! HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->exists();
            if ($open) {
                $open->update(['until' => $today]);
                $changes++;
            }
            if ($label !== null) {
                HistoryEntry::create(['user_id' => $user->id, 'kind' => $kind, 'label' => $label, 'since' => $first ? $firstSince : $today]);
                $changes++;
            }
        };

        $many = function (string $kind, Collection $current) use ($user, $today, &$changes) {
            $first = ! HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->exists();
            $open = HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->whereNull('until')->whereNotNull('ref_id')->get()->keyBy('ref_id');
            foreach ($open as $refId => $entry) {
                if (! $current->has($refId)) {
                    $entry->update(['until' => $today]);
                    $changes++;
                }
            }
            foreach ($current as $refId => $label) {
                if (! $open->has($refId)) {
                    HistoryEntry::create(['user_id' => $user->id, 'kind' => $kind, 'ref_id' => $refId, 'label' => $label, 'since' => $first ? $user->joined_at : $today]);
                    $changes++;
                }
            }
        };

        DB::transaction(function () use ($user, $single, $many) {
            $single('rank', filled($user->rank) ? $user->rank : null, $user->rank_since);
            $single('status', $user->status?->label(), $user->joined_at);
            $many('group', $user->groups->mapWithKeys(fn (Group $g) => [$g->id => $g->name]));
            $many('function', $user->functions->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()]));
        });

        return $changes;
    }

    public function syncAll(): int
    {
        $changes = 0;
        User::query()->orderBy('id')->each(function (User $user) use (&$changes) {
            try {
                $changes += $this->syncHistory($user);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $changes;
    }

    /** A former stage from the paper file, e.g. "Oberfeuerwehrmann 2008–2012" */
    public function addHistory(User $by, User $member, array $data): HistoryEntry
    {
        $this->assertMayView($by, $member);
        [$kind, $label, $since, $until] = $this->validStage($data, true);

        return HistoryEntry::create(['user_id' => $member->id, 'kind' => $kind, 'label' => $label, 'since' => $since, 'until' => $until,
            'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null, 'created_by' => $by->id]);
    }

    /** Correct the dates or the note of a stage; the label of a current stage comes from the member data */
    public function updateHistory(User $by, HistoryEntry $entry, array $data): void
    {
        $this->assertMayView($by, $entry->user);
        [, $label, $since, $until] = $this->validStage([...$data, 'kind' => $entry->kind, 'label' => $data['label'] ?? $entry->label], $entry->until !== null);
        $entry->update(['label' => $entry->until === null ? $entry->label : $label, 'since' => $since,
            'until' => $entry->until === null ? null : $until, 'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null]);
    }

    public function deleteHistory(User $by, HistoryEntry $entry): void
    {
        $this->assertMayView($by, $entry->user);
        if ($entry->until === null) {
            throw ValidationException::withMessages(['history' => 'Die aktuelle Station kommt aus den Mitgliedsdaten. Dort ändern, dann wird sie hier abgeschlossen.']);
        }
        $entry->delete();
    }

    // ─── Ehrungen ────────────────────────────────────────────────────────────

    public function saveHonorType(User $by, ?HonorType $type, string $name, ?int $afterYears): HonorType
    {
        $this->assertManager($by);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['type_name' => 'Bitte einen Namen angeben (höchstens 150 Zeichen).']);
        }
        if ($afterYears !== null && ($afterYears < 1 || $afterYears > 80)) {
            throw ValidationException::withMessages(['type_years' => 'Dienstjahre zwischen 1 und 80, oder leer lassen.']);
        }
        if (HonorType::where('name', $name)->when($type, fn ($q) => $q->whereKeyNot($type->id))->exists()) {
            throw ValidationException::withMessages(['type_name' => 'Diese Ehrung gibt es schon.']);
        }

        $type ??= new HonorType(['sort_order' => (int) HonorType::max('sort_order') + 1]);
        $type->fill(['name' => $name, 'after_years' => $afterYears])->save();

        return $type;
    }

    public function deleteHonorType(User $by, HonorType $type): void
    {
        $this->assertManager($by);
        $type->delete();   // received Ehrungen keep their title
    }

    public function addHonor(User $by, User $member, array $data): Honor
    {
        $this->assertMayView($by, $member);
        $type = filled($data['honor_type_id'] ?? null) ? HonorType::find((int) $data['honor_type_id']) : null;
        $title = trim((string) ($data['title'] ?? '')) ?: $type?->name;
        if (! $title) {
            throw ValidationException::withMessages(['honor_title' => 'Bitte die Ehrung wählen oder benennen.']);
        }
        $awarded = $this->date($data['awarded_on'] ?? null, 'honor_date') ?? throw ValidationException::withMessages(['honor_date' => 'Bitte das Datum der Ehrung angeben.']);
        if ($awarded->gt(self::today())) {
            throw ValidationException::withMessages(['honor_date' => 'Das Datum liegt in der Zukunft.']);
        }

        return Honor::create(['user_id' => $member->id, 'honor_type_id' => $type?->id, 'title' => mb_substr($title, 0, 200), 'awarded_on' => $awarded,
            'awarded_by' => filled($data['awarded_by'] ?? null) ? mb_substr(trim($data['awarded_by']), 0, 150) : null,
            'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null, 'created_by' => $by->id]);
    }

    public function deleteHonor(User $by, Honor $honor): void
    {
        $this->assertMayView($by, $honor->user);
        $honor->delete();
    }

    /** Full Dienstjahre on a day, counted from the Eintritt */
    public static function serviceYears(User $user, ?Carbon $on = null): ?int
    {
        return $user->joined_at ? (int) $user->joined_at->diffInYears($on ?? self::today()) : null;
    }

    /**
     * Ehrungen that are due by the end of $year (for the Jahreshauptversammlung): every kind with
     * Dienstjahre that an active Kamerad reaches by then and hasn't received yet.
     *
     * @return Collection<int, array{user: User, type: HonorType, reached_on: Carbon, overdue: bool}>
     */
    public function dueHonors(User $manager, int $year, ?User $only = null): Collection
    {
        $types = HonorType::whereNotNull('after_years')->orderBy('after_years')->get();
        if ($types->isEmpty()) {
            return collect();
        }
        $endOfYear = Carbon::create($year, 12, 31);
        $members = ($only ? User::whereKey($only->id) : self::visibleMembers($manager))
            ->where('status', 'active')->whereNotNull('joined_at')->orderBy('name')->get();
        // Which kinds each Kamerad already has (no relation on the core User model)
        $received = Honor::whereIn('user_id', $members->pluck('id'))->whereNotNull('honor_type_id')->get()
            ->groupBy('user_id')->map(fn ($honors) => $honors->pluck('honor_type_id')->all());

        return $members->flatMap(fn (User $member) => $types
            ->filter(fn (HonorType $t) => $member->joined_at->copy()->addYears($t->after_years)->lte($endOfYear)
                && ! in_array($t->id, $received[$member->id] ?? [], true))
            ->map(fn (HonorType $t) => ['user' => $member, 'type' => $t, 'reached_on' => $reached = $member->joined_at->copy()->addYears($t->after_years),
                'overdue' => $reached->year < $year]))
            ->sortBy(fn ($row) => $row['reached_on']->timestamp)->values();
    }

    // ─── Dienstausweis ───────────────────────────────────────────────────────

    public function saveIdCard(User $by, User $member, ?string $number, ?string $validUntil): MemberProfile
    {
        $this->assertMayView($by, $member);

        return MemberProfile::updateOrCreate(['user_id' => $member->id], [
            'id_card_number' => filled($number) ? mb_substr(trim($number), 0, 50) : null,
            'id_card_valid_until' => $this->date($validUntil, 'id_card_valid_until'),
        ]);
    }

    // ─── Dienstbeteiligung ───────────────────────────────────────────────────

    public static function attendanceAvailable(): bool
    {
        return app(ModuleRegistry::class)->isActive('kalender');
    }

    /**
     * From the Kalender: the Dienste of $year (types, not cancelled, over) that were meant for the
     * Kamerad and where attendance was taken, and how many of them he attended. Before the Eintritt
     * nothing counts.
     *
     * @return array{total: int, attended: int, percent: ?int}
     */
    public function attendance(User $member, int $year, array $types = self::DUTY_TYPES): array
    {
        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();
        $until = Carbon::create($year + 1, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc()->min(now());
        if ($member->joined_at && $member->joined_at->gt($from)) {
            $from = $member->joined_at->copy()->setTimezone('Europe/Berlin')->startOfDay()->utc();
        }

        $events = Audience::scope(Event::query(), $member)
            ->whereNull('source_id')->whereNull('cancelled_at')->whereIn('type', $types)
            ->where('starts_at', '>=', $from)->where('ends_at', '<=', $until)
            // Attendance was taken: someone is marked present or absent
            ->whereHas('responses', fn ($q) => $q->whereNotNull('attended'))
            ->pluck('id');
        $attended = $events->isEmpty() ? 0 : Response::whereIn('event_id', $events)->where('user_id', $member->id)->where('attended', true)->count();
        $total = $events->count();

        return ['total' => $total, 'attended' => $attended, 'percent' => $total > 0 ? (int) round($attended / $total * 100) : null];
    }

    /** CSV for Excel: one line per Kamerad */
    public function attendanceCsv(User $manager, int $year, array $types): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Abteilungen', 'Dienste', 'Anwesend', 'Dienstbeteiligung %'], ';');
        foreach (self::visibleMembers($manager)->where('status', 'active')->with('groups')->orderBy('name')->get() as $member) {
            $a = $this->attendance($member, $year, $types);
            fputcsv($out, [$member->name, $member->groups->pluck('name')->implode(', '), $a['total'], $a['attended'], $a['percent'] ?? ''], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: ?Carbon, 3: ?Carbon} */
    private function validStage(array $data, bool $needsUntil): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! array_key_exists($kind, HistoryEntry::KINDS)) {
            throw ValidationException::withMessages(['history_kind' => 'Bitte wählen, was es war.']);
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 200) {
            throw ValidationException::withMessages(['history_label' => 'Bitte angeben, z. B. den Dienstgrad oder die Abteilung.']);
        }
        $since = $this->date($data['since'] ?? null, 'history_since');
        $until = $this->date($data['until'] ?? null, 'history_until');
        if ($needsUntil && ! $until) {
            throw ValidationException::withMessages(['history_until' => 'Eine frühere Station braucht ein Ende. Die aktuelle kommt aus den Mitgliedsdaten.']);
        }
        if ($since && $until && $until->lt($since)) {
            throw ValidationException::withMessages(['history_until' => '„Bis“ liegt vor „seit“.']);
        }
        if (($until ?? $since)?->gt(self::today())) {
            throw ValidationException::withMessages(['history_until' => 'Das Datum liegt in der Zukunft.']);
        }

        return [$kind, $label, $since, $until];
    }

    private function date(?string $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Bitte ein gültiges Datum angeben.']);
        }
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['personal' => 'Das dürfen nur Personalverwalter und Admins.']);
        }
    }

    private function assertMayView(User $by, User $member): void
    {
        if (! self::mayView($by, $member)) {
            throw ValidationException::withMessages(['personal' => 'Diese Personalakte darfst du nicht bearbeiten.']);
        }
    }
}
