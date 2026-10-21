<?php

namespace Extensions\Anwesenheit\Services;

use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Support\FileResponse;
use Carbon\Carbon;
use Extensions\Anwesenheit\Mail\AttendanceMail;
use Extensions\Anwesenheit\Models\AttendanceFile;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Models\AttendanceTemplate;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationModuleRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Throwable;

/**
 * Owns every change to an Anwesenheitsliste. Standort rules come from the Locations extension: the lists,
 * templates and members are filtered by its LocationScope, and a list can only be written to a Standort
 * the user belongs to.
 */
class AttendanceService
{
    public const MAX_MINUTES = 7 * 24 * 60;

    public static function locationsOn(): bool
    {
        return (bool) config('locations.enabled') && Schema::hasTable('x_locations');
    }

    /** Global admins, Standort-Admins (of their own Standort) and users with the module role "verwalter" */
    public static function canManage(User $user): bool
    {
        if (app(LocationModuleRoles::class)->enabled('anwesenheit')) {
            return $user->canUseModule('anwesenheit') && $user->hasAnyModuleRole('anwesenheit', 'verwalter');
        }

        if (! $user->is_active) {
            return false;
        }

        return $user->is_admin
            || (self::locationsOn() && app(LocationAccess::class)->isLocationAdmin($user))
            || $user->hasModuleRole('anwesenheit', 'verwalter');
    }

    public static function canSee(User $user, AttendanceList $list): bool
    {
        return self::canAccessLocation($user, $list->ext_location_id) && (self::canManageAt($user, $list->ext_location_id)
            || (int) $list->created_by === (int) $user->id
            || (int) $list->leader_id === (int) $user->id
            || $list->participants()->whereKey($user->id)->exists());
    }

    public static function canManageAt(User $user, ?int $locationId): bool
    {
        if (! self::canAccessLocation($user, $locationId)) {
            return false;
        }

        if (app(LocationModuleRoles::class)->enabled('anwesenheit')) {
            return $user->hasModuleRole('anwesenheit', 'verwalter', $locationId);
        }

        return $user->is_admin || $user->hasModuleRole('anwesenheit', 'verwalter', $locationId)
            || (self::locationsOn() && in_array($locationId, app(LocationAccess::class)->adminLocationIds($user), true));
    }

    private static function canAccessLocation(User $user, ?int $locationId): bool
    {
        $roles = app(LocationModuleRoles::class);
        if ($roles->enabled('anwesenheit')) {
            return $user->canUseModule('anwesenheit')
                && ($roles->isGlobalAdmin($user) || $roles->canAccessLocation($user, $locationId));
        }

        if (self::locationsOn()) {
            app(LocationAccess::class)->forget($user);
        }

        return $user->is_active && $user->canUseModule('anwesenheit') && ($user->is_admin || ! self::locationsOn()
            || ($locationId !== null && app(LocationAccess::class)->canSee($user, $locationId)));
    }

    /** The creator edits until the list is submitted; managers also correct submitted lists */
    public static function canEdit(User $user, AttendanceList $list): bool
    {
        return self::canAccessLocation($user, $list->ext_location_id)
            && (self::canManageAt($user, $list->ext_location_id) || ((int) $list->created_by === (int) $user->id && ! $list->isSubmitted()));
    }

    public static function canResend(User $user, AttendanceList $list): bool
    {
        return $list->isSubmitted() && self::canAccessLocation($user, $list->ext_location_id)
            && (self::canManageAt($user, $list->ext_location_id) || (int) $list->created_by === (int) $user->id);
    }

    /** @return Builder<AttendanceList> */
    public function visibleTo(User $user): Builder
    {
        $query = AttendanceList::withoutGlobalScope(LocationScope::class);

        $roles = app(LocationModuleRoles::class);
        if ($roles->enabled('anwesenheit')) {
            if (! $user->canUseModule('anwesenheit')) {
                return $query->whereRaw('1 = 0');
            }
            if ($roles->isGlobalAdmin($user)) {
                return $query;
            }
            $locations = Location::where('is_active', true)->pluck('id')
                ->filter(fn ($id) => $roles->canAccessLocation($user, (int) $id))->all();
            $managed = $roles->roleLocationIds($user, 'anwesenheit', 'verwalter');

            return $query->whereIn('ext_location_id', $locations)
                ->where(fn (Builder $q) => $q->whereIn('ext_location_id', $managed)
                    ->orWhere('created_by', $user->id)->orWhere('leader_id', $user->id)
                    ->orWhereIn('id', DB::table('x_att_participants')->where('user_id', $user->id)->select('list_id')));
        }

        if (! $user->is_active || ! $user->canUseModule('anwesenheit')) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->is_admin) {
            return $query;
        }

        if (self::locationsOn()) {
            app(LocationAccess::class)->forget($user);
            $query->whereIn('ext_location_id', app(LocationAccess::class)->visibleLocationIds($user));
        }

        if (! $user->hasModuleRole('anwesenheit', 'verwalter')) {
            $managed = self::locationsOn() ? app(LocationAccess::class)->adminLocationIds($user) : [];
            $query->where(fn (Builder $q) => $q->whereIn('ext_location_id', $managed)
                ->orWhere('created_by', $user->id)->orWhere('leader_id', $user->id)
                ->orWhereIn('id', DB::table('x_att_participants')->where('user_id', $user->id)->select('list_id')));
        }

        return $query;
    }

    /** @return array<int, string> Standorte the user may choose for a list (empty without the Locations extension) */
    public function locationsFor(User $user): array
    {
        if (! self::locationsOn()) {
            return [];
        }

        return Location::query()->whereIn('id', app(LocationAccess::class)->visibleLocationIds($user))
            ->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The Standort a new list starts in */
    public function defaultLocation(User $user): ?int
    {
        return self::locationsOn() ? app(LocationAccess::class)->currentLocationId($user) : null;
    }

    /**
     * Kameraden who can be on a list of that Standort: those of the Standort and those without one. Users
     * who are not global admins only ever see their own Standorte anyway (LocationScope).
     *
     * @return Collection<int, User>
     */
    public function members(?int $locationId): Collection
    {
        return User::active()
            ->when($locationId && self::locationsOn(), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('users.ext_location_id')->orWhere('users.ext_location_id', $locationId)
                ->orWhereIn('users.id', DB::table('x_location_user')->where('location_id', $locationId)->select('user_id'))))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Optional shortcuts that prefill the form: templates and, when the Kalender is on, today's and recent
     * Übungen. Nothing is stored about the source except its key.
     *
     * @return list<array{key: string, label: string}>
     */
    public function prefillOptions(User $user): array
    {
        $options = AttendanceTemplate::where('is_active', true)->orderBy('name')->get()
            ->map(fn (AttendanceTemplate $t) => ['key' => 'tpl:'.$t->id, 'label' => 'Vorlage: '.$t->name])->all();

        foreach ($this->events($user) as $event) {
            $options[] = ['key' => 'evt:'.$event->id, 'label' => 'Termin: '.$event->title.' ('.$event->starts_at->timezone('Europe/Berlin')->format('d.m.Y H:i').')'];
        }

        return $options;
    }

    /** @return array{title: ?string, kind: string, starts_at: ?string, ends_at: ?string, leader_id: ?int, recipients: ?string, template_id: ?int, source_event_id: ?int}|null */
    public function prefill(User $user, string $key): ?array
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        $blank = ['title' => null, 'kind' => 'uebung', 'starts_at' => null, 'ends_at' => null, 'leader_id' => null, 'recipients' => null, 'template_id' => null, 'source_event_id' => null];

        if ($type === 'tpl' && ($template = AttendanceTemplate::where('is_active', true)->find((int) $id))) {
            $start = now('Europe/Berlin')->startOfMinute();

            return [...$blank, 'title' => $template->title ?: $template->name, 'kind' => $template->kind, 'leader_id' => $template->leader_id, 'recipients' => $template->recipients,
                'template_id' => $template->id, 'starts_at' => $template->duration_minutes ? $start->copy()->subMinutes($template->duration_minutes)->format('Y-m-d\TH:i') : null,
                'ends_at' => $template->duration_minutes ? $start->format('Y-m-d\TH:i') : null];
        }

        if ($type === 'evt' && ($event = $this->events($user)->firstWhere('id', (int) $id))) {
            return [...$blank, 'title' => $event->title, 'kind' => $event->type === 'uebung' ? 'uebung' : 'sonstiges', 'source_event_id' => $event->id,
                'starts_at' => $event->starts_at->timezone('Europe/Berlin')->format('Y-m-d\TH:i'), 'ends_at' => $event->ends_at->timezone('Europe/Berlin')->format('Y-m-d\TH:i')];
        }

        return null;
    }

    /** @return Collection<int, Event> */
    private function events(User $user): Collection
    {
        $event = Event::class;

        if (! class_exists($event) || ! in_array('kalender', $this->activeModules(), true)) {
            return collect();
        }

        return $event::query()->visibleTo($user)->whereNull('cancelled_at')->where('all_day', false)
            ->whereBetween('starts_at', [now()->subDays(7), now()->addDay()])->orderByDesc('starts_at')->limit(25)->get();
    }

    /** @return list<string> */
    private function activeModules(): array
    {
        return Module::where('is_active', true)->pluck('key')->all();
    }

    public static function defaultRecipients(): string
    {
        return (string) Setting::get('anwesenheit.recipients', '');
    }

    /** Local Berlin "Y-m-d\TH:i" from the form to UTC */
    public static function parseTime(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, 'Europe/Berlin')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public static function minutesBetween(?Carbon $start, ?Carbon $end): int
    {
        return $start && $end && $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : 0;
    }

    /**
     * Saves the list. $data: title, kind, ext_location_id, starts_at, ends_at (local strings), leader_id, guests, notes,
     * recipients, template_id, source_event_id. With $submit the list is finished: all required data must be there.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $participantIds
     * @param  list<UploadedFile>  $uploads
     */
    public function save(User $by, ?AttendanceList $list, array $data, array $participantIds, array $uploads = [], bool $submit = false): AttendanceList
    {
        abort_unless($by->is_active && $by->canUseModule('anwesenheit'), 403);

        if ($list && ! self::canEdit($by, $list)) {
            throw ValidationException::withMessages(['form' => 'Diese Liste kann nicht mehr bearbeitet werden.']);
        }

        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        $kind = (string) ($data['kind'] ?? 'uebung');
        $start = self::parseTime($data['starts_at'] ?? null);
        $end = self::parseTime($data['ends_at'] ?? null);
        $leaderId = ! empty($data['leader_id']) ? (int) $data['leader_id'] : null;
        $guests = max(0, min(999, (int) ($data['guests'] ?? 0)));
        $locationId = ! empty($data['ext_location_id']) ? (int) $data['ext_location_id'] : null;

        if ($title === '' || mb_strlen($title) > 150) {
            $errors['form.title'] = 'Bitte einen Titel (höchstens 150 Zeichen) angeben.';
        }
        if (! array_key_exists($kind, config('anwesenheit.kinds'))) {
            $errors['form.kind'] = 'Unbekannte Art.';
        }
        if (($start xor $end) || ($submit && ! $start)) {
            $errors['form.starts_at'] = 'Bitte Beginn und Ende angeben.';
        } elseif ($start && $end && ! $end->greaterThan($start)) {
            $errors['form.ends_at'] = 'Das Ende muss nach dem Beginn liegen.';
        } elseif ($start && $end && self::minutesBetween($start, $end) > self::MAX_MINUTES) {
            $errors['form.ends_at'] = 'Die Dauer darf höchstens sieben Tage betragen.';
        }
        if ($locationId !== null && (! self::locationsOn() || ! in_array($locationId, array_keys($this->locationsFor($by)), true))) {
            $errors['form.ext_location_id'] = 'Kein Zugriff auf diesen Standort.';
        }
        if ($list && $locationId !== null && $locationId !== $list->ext_location_id
            && ! self::canManageAt($by, $locationId)) {
            $errors['form.ext_location_id'] = 'Keine Verwaltungsberechtigung für diesen Standort.';
        }

        $recipients = (string) ($data['recipients'] ?? '');
        $invalid = collect(preg_split('/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY))->reject(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
        if ($invalid->isNotEmpty()) {
            $errors['form.recipients'] = 'Ungültige E-Mail-Adresse: '.$invalid->implode(', ');
        }

        // The leader takes part; everybody must belong to the Standort of the list
        $ids = collect($participantIds)->map(fn ($id) => (int) $id)->when($leaderId, fn ($c) => $c->push($leaderId))->unique()->values();
        $allowed = $this->members($locationId ?? ($list?->ext_location_id))->pluck('id');
        if ($ids->diff($allowed)->isNotEmpty()) {
            $errors['selected'] = 'Mindestens eine Person gehört nicht zu diesem Standort oder ist nicht aktiv.';
        }
        if ($submit) {
            if ($leaderId === null) {
                $errors['form.leader_id'] = 'Bitte die Leitung auswählen.';
            }
            if ($ids->isEmpty() && $guests === 0) {
                $errors['selected'] = 'Bitte mindestens eine anwesende Person auswählen.';
            }
        }

        $existingFiles = $list ? $list->files()->count() : 0;
        foreach ($uploads as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            if (! in_array($ext, FileResponse::allowedExtensions(), true)) {
                $errors['uploads'] = 'Dateityp ".'.$ext.'" ist nicht erlaubt.';
            } elseif ($upload->getSize() > config('anwesenheit.max_file_kb') * 1024) {
                $errors['uploads'] = 'Eine Datei ist größer als '.config('anwesenheit.max_file_kb').' KB.';
            }
        }
        if ($existingFiles + count($uploads) > config('anwesenheit.max_files')) {
            $errors['uploads'] = 'Höchstens '.config('anwesenheit.max_files').' Dateien je Liste.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $list, $title, $kind, $start, $end, $leaderId, $guests, $locationId, $recipients, $data, $ids, $uploads, $submit) {
            $list ??= new AttendanceList(['created_by' => $by->id]);
            $list->fill([
                'title' => $title, 'kind' => $kind, 'starts_at' => $start, 'ends_at' => $end,
                'duration_minutes' => self::minutesBetween($start, $end), 'leader_id' => $leaderId, 'guests' => $guests,
                'participant_count' => $ids->count() + $guests, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'recipients' => trim($recipients) ?: null,
                'template_id' => $data['template_id'] ?? $list->template_id, 'source_event_id' => $data['source_event_id'] ?? $list->source_event_id,
            ]);
            if ($locationId !== null) {
                $list->ext_location_id = $locationId;
            }
            if ($submit) {
                $list->status = AttendanceList::SUBMITTED;
                $list->submitted_at ??= now();
            }
            $list->save();
            $list->participants()->sync($ids->all());

            foreach ($uploads as $upload) {
                $list->files()->create([
                    'filename' => mb_substr($upload->getClientOriginalName(), 0, 200),
                    'size' => (int) $upload->getSize(),
                    'data' => base64_encode((string) file_get_contents($upload->getRealPath())),
                ]);
            }

            return $list;
        });
    }

    public function removeFile(User $by, AttendanceFile $file): void
    {
        $file->loadMissing('list');
        if (! self::canEdit($by, $file->list)) {
            throw ValidationException::withMessages(['form' => 'Diese Liste kann nicht mehr bearbeitet werden.']);
        }
        $file->delete();
    }

    /** Only managers delete, and the creator while the list is still a draft */
    public function delete(User $by, AttendanceList $list): void
    {
        if (! self::canEdit($by, $list)) {
            throw ValidationException::withMessages(['form' => 'Diese Liste kann nicht gelöscht werden.']);
        }
        $list->delete();
    }

    /** @return list<string> the addresses a list is sent to: its own, else the settings default */
    public static function recipientsFor(AttendanceList $list): array
    {
        return AttendanceList::parseRecipients($list->recipients) ?: AttendanceList::parseRecipients(self::defaultRecipients());
    }

    /** Mail with the PDF and the uploads; a failure is reported but never thrown (the list stays saved) */
    public function send(User $by, AttendanceList $list): bool
    {
        abort_unless(self::canResend($by, $list), 403);

        $to = self::recipientsFor($list);
        if ($to === []) {
            return false;
        }

        try {
            Mail::to($to)->send(new AttendanceMail($list->loadMissing(['leader', 'files']), app(AttendancePdf::class)->render($list)));
            $list->forceFill(['mailed_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
