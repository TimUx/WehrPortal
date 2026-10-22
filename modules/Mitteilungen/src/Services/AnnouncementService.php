<?php

namespace Modules\Mitteilungen\src\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Mitteilungen\Services\AnnouncementDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Throwable;

class AnnouncementService
{
    /**
     * @param  array{title: string, body?: ?string, important: bool, needs_ack?: bool, visible_from?: mixed, visible_until?: mixed}  $data
     * @param  int[]  $groupIds  audience by Abteilung; with $functionIds empty = everyone
     * @param  int[]  $functionIds  audience by Funktion
     * @param  list<UploadedFile>  $files  attachments to add
     */
    public function save(?Announcement $announcement, array $data, array $groupIds, User $by, bool $mail = false, bool $push = false, array $functionIds = [], array $files = [], bool $ntfy = false, bool $matrix = false, ?int $locationId = null): Announcement
    {
        if ($announcement) {
            $announcement = $announcement->fresh() ?? abort(404);
        }
        $this->assertCanManageScope($by, $locationId);
        $this->assertAudience($by, $groupIds, $functionIds, $locationId);

        foreach ($files as $file) {
            if (! in_array(mb_strtolower($file->getClientOriginalExtension()), AnnouncementFile::EXTENSIONS, true) || $file->getSize() > AnnouncementFile::MAX_KB * 1024) {
                throw ValidationException::withMessages(['files' => '„'.$file->getClientOriginalName().'“: bitte PDF, Office-Datei oder Bild bis 10 MB.']);
            }
        }

        if ($announcement) {
            $this->assertCanManage($by, $announcement);
        }

        $announcement = DB::transaction(function () use ($announcement, $data, $groupIds, $functionIds, $by, $files, $locationId) {
            $announcement ??= new Announcement(['created_by' => $by->id]);
            $announcement->fill([...$data, 'ext_location_id' => $locationId])->save();
            $announcement->groups()->sync($groupIds);
            $announcement->functions()->sync($functionIds);
            foreach ($files as $file) {
                $announcement->files()->create(['filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(), 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]);
            }

            return $announcement;
        });

        if ($this->isPublished($announcement)) {
            if ($mail) {
                $this->mail($announcement, by: $by);
            } elseif ($push) {
                $this->mail($announcement, ['database'], by: $by);
            }
        }

        if ($ntfy || $matrix) {
            try {
                $this->externalStatus = app(AnnouncementDelivery::class)
                    ->send($announcement, ['ntfy' => $ntfy, 'matrix' => $matrix], $by);
            } catch (Throwable) {
                $this->externalStatus = array_values(array_filter([
                    $ntfy ? 'ntfy: Versandstatus nicht verfügbar; die Mitteilung bleibt in der App gespeichert.' : null,
                    $matrix ? 'Matrix: Versandstatus nicht verfügbar; die Mitteilung bleibt in der App gespeichert.' : null,
                ]));
            }
        } else {
            $this->externalStatus = [];
        }

        return $announcement;
    }

    /** @var list<string> */
    public array $externalStatus = [];

    public function isPublished(Announcement $announcement): bool
    {
        return (! $announcement->visible_from || ! $announcement->visible_from->isFuture())
            && (! $announcement->visible_until || ! $announcement->visible_until->isPast());
    }

    /** Everyone it is meant for and who may open the Mitteilungen, so links don't end in "kein Zugriff" */
    public function recipients(Announcement $announcement): EloquentCollection
    {
        $groupIds = DB::table('announcement_group')->where('announcement_id', $announcement->id)->pluck('group_id')->all();
        $functionIds = DB::table('announcement_function')->where('announcement_id', $announcement->id)->pluck('member_function_id')->all();
        $functionIds = array_values(array_unique([
            ...array_map('intval', $functionIds),
            ...DB::table('member_functions')->whereIn('parent_id', $functionIds)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]));
        $everyone = $groupIds === [] && $functionIds === [];

        $users = User::active();
        if (class_exists(LocationScope::class)) {
            $users->withoutGlobalScope(LocationScope::class);
        }

        $users->when(! $everyone, fn ($q) => $q->where(function ($w) use ($groupIds, $functionIds) {
            $w->whereExists(fn ($membership) => $membership->selectRaw('1')->from('group_user')
                ->whereColumn('group_user.user_id', 'users.id')->whereIn('group_user.group_id', $groupIds))
                ->orWhereExists(fn ($membership) => $membership->selectRaw('1')->from('member_function_user')
                    ->whereColumn('member_function_user.user_id', 'users.id')
                    ->whereIn('member_function_user.member_function_id', $functionIds));
        }));

        if ($announcement->ext_location_id && Schema::hasTable('x_location_user')
            && Schema::hasColumn('users', 'ext_location_id')) {
            $locationId = (int) $announcement->ext_location_id;
            $users->where(fn ($q) => $q->where('users.ext_location_id', $locationId)
                ->orWhereIn('users.id', DB::table('x_location_user')->where('location_id', $locationId)->select('user_id')));
        } elseif ($announcement->ext_location_id) {
            $users->whereRaw('1 = 0');
        }

        return $users->orderBy('name')->get()->filter(fn (User $user) => $user->canUseModule('mitteilungen'))->values();
    }

    /** Whether this user may manage the announcement's scope. */
    public function canManage(User $user, Announcement $announcement): bool
    {
        $locationId = $announcement->ext_location_id ? (int) $announcement->ext_location_id : null;

        return $this->canManageScope($user, $locationId)
            && $this->audienceWithinScopeRights($user, $announcement->id, $locationId);
    }

    public function assertCanManage(User $user, Announcement $announcement): void
    {
        abort_unless($this->canManage($user, $announcement), 403);
    }

    /** Only a global admin can publish to a scope outside their own location-admin/editor rights. */
    public function assertCanManageScope(User $user, ?int $locationId): void
    {
        abort_unless($this->canManageScope($user, $locationId), 403);
    }

    public function canManageScope(User $user, ?int $locationId): bool
    {
        if ($user->is_admin) {
            return $locationId === null || (config('locations.enabled') && Schema::hasTable('x_locations')
                && Location::query()->whereKey($locationId)->exists());
        }

        $access = config('locations.enabled') && app()->bound(LocationAccess::class) ? app(LocationAccess::class) : null;
        $isLocationAdmin = $access?->isLocationAdmin($user) ?? false;

        if ($locationId === null) {
            return $user->canPostAnnouncements() && ! $isLocationAdmin;
        }

        return $access && $access->canSee($user, $locationId) && ($isLocationAdmin
            ? in_array($locationId, $access->adminLocationIds($user), true)
            : $user->canPostAnnouncements());
    }

    /** @param int[] $groupIds @param int[] $functionIds */
    private function assertAudience(User $user, array $groupIds, array $functionIds, ?int $locationId): void
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        $functionIds = array_values(array_unique(array_map('intval', $functionIds)));

        $hasLocations = Schema::hasColumn('groups', 'ext_location_id')
            && Schema::hasColumn('member_functions', 'ext_location_id');
        $groups = DB::table('groups')->whereIn('id', $groupIds)->get($hasLocations ? ['id', 'ext_location_id'] : ['id']);
        $functions = DB::table('member_functions')->whereIn('id', $functionIds)->get($hasLocations ? ['id', 'ext_location_id'] : ['id']);

        abort_unless($groups->count() === count($groupIds) && $functions->count() === count($functionIds), 403);

        if ($hasLocations && ! $this->isManagementAdmin($user)) {
            $allowedIds = $locationId !== null
                ? [$locationId]
                : (app()->bound(LocationAccess::class) ? app(LocationAccess::class)->visibleLocationIds($user) : []);
            abort_unless($groups->every(fn ($group) => $group->ext_location_id === null || in_array((int) $group->ext_location_id, $allowedIds, true)), 403);
            abort_unless($functions->every(fn ($function) => $function->ext_location_id === null || in_array((int) $function->ext_location_id, $allowedIds, true)), 403);
        }
    }

    public function canManageAnything(User $user): bool
    {
        return $user->is_admin || $user->canPostAnnouncements()
            || (config('locations.enabled') && app()->bound(LocationAccess::class) && app(LocationAccess::class)->isLocationAdmin($user));
    }

    protected function isManagementAdmin(User $user): bool
    {
        return $user->is_admin;
    }

    /** @return list<int> */
    public function manageableLocationIds(User $user): array
    {
        $access = config('locations.enabled') && app()->bound(LocationAccess::class) ? app(LocationAccess::class) : null;

        return $access
            ? ($user->is_admin ? Location::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
                : ($access->isLocationAdmin($user) ? $access->adminLocationIds($user) : $access->visibleLocationIds($user)))
            : [];
    }

    /** Extensions can require an explicit, current actor for non-interactive actions. */
    public function assertActionAuthority(Announcement $announcement, ?User $by = null): void {}

    protected function managementScopeQuery(User $user, Builder $query): Builder
    {
        $access = config('locations.enabled') && app()->bound(LocationAccess::class) ? app(LocationAccess::class) : null;
        if ($access?->isLocationAdmin($user)) {
            return $query->whereIn('ext_location_id', $access->adminLocationIds($user));
        }
        if ($user->canPostAnnouncements()) {
            return $query->where(fn (Builder $q) => $q->whereNull('ext_location_id')->orWhereIn('ext_location_id', $access?->visibleLocationIds($user) ?? []));
        }

        return $query->whereRaw('1 = 0');
    }

    /** @return Builder<Announcement> */
    public function managementQuery(User $user): Builder
    {
        $query = Announcement::query();
        if ($this->isManagementAdmin($user)) {
            return $query;
        }

        $access = config('locations.enabled') && app()->bound(LocationAccess::class) ? app(LocationAccess::class) : null;
        $query = $this->managementScopeQuery($user, $query);

        if (Schema::hasColumn('groups', 'ext_location_id')) {
            $visibleIds = $access?->visibleLocationIds($user) ?? [];
            $query->whereNotExists(function ($foreign) use ($visibleIds) {
                $foreign->selectRaw('1')->from('announcement_group as ag')
                    ->join('groups as g', 'g.id', '=', 'ag.group_id')
                    ->whereColumn('ag.announcement_id', 'announcements.id')
                    ->whereNotNull('g.ext_location_id')
                    ->where(function ($scope) use ($visibleIds) {
                        $scope->where(function ($general) use ($visibleIds) {
                            $general->whereNull('announcements.ext_location_id');
                            $visibleIds === [] ? $general->whereNotNull('g.ext_location_id') : $general->whereNotIn('g.ext_location_id', $visibleIds);
                        })->orWhere(fn ($specific) => $specific->whereNotNull('announcements.ext_location_id')
                            ->whereColumn('g.ext_location_id', '!=', 'announcements.ext_location_id'));
                    });
            });
        }

        if (Schema::hasColumn('member_functions', 'ext_location_id')) {
            $visibleIds = $access?->visibleLocationIds($user) ?? [];
            $query->whereNotExists(function ($foreign) use ($visibleIds) {
                $foreign->selectRaw('1')->from('announcement_function as af')
                    ->join('member_functions as f', 'f.id', '=', 'af.member_function_id')
                    ->whereColumn('af.announcement_id', 'announcements.id')
                    ->whereNotNull('f.ext_location_id')
                    ->where(function ($scope) use ($visibleIds) {
                        $scope->where(function ($general) use ($visibleIds) {
                            $general->whereNull('announcements.ext_location_id');
                            $visibleIds === [] ? $general->whereNotNull('f.ext_location_id') : $general->whereNotIn('f.ext_location_id', $visibleIds);
                        })->orWhere(fn ($specific) => $specific->whereNotNull('announcements.ext_location_id')
                            ->whereColumn('f.ext_location_id', '!=', 'announcements.ext_location_id'));
                    });
            });
        }

        return $query;
    }

    private function audienceWithinScopeRights(User $user, int $announcementId, ?int $locationId): bool
    {
        if ($this->isManagementAdmin($user)) {
            return true;
        }

        $visibleIds = $locationId === null
            ? (app()->bound(LocationAccess::class) ? app(LocationAccess::class)->visibleLocationIds($user) : [])
            : [$locationId];

        if (Schema::hasColumn('groups', 'ext_location_id')) {
            $foreignGroups = DB::table('announcement_group as ag')->join('groups as g', 'g.id', '=', 'ag.group_id')
                ->where('ag.announcement_id', $announcementId)->whereNotNull('g.ext_location_id');
            $locationId === null
                ? ($visibleIds === [] ? $foreignGroups : $foreignGroups->whereNotIn('g.ext_location_id', $visibleIds))
                : $foreignGroups->where('g.ext_location_id', '!=', $locationId);
            if ($foreignGroups->exists()) {
                return false;
            }
        }

        if (Schema::hasColumn('member_functions', 'ext_location_id')) {
            $foreignFunctions = DB::table('announcement_function as af')->join('member_functions as f', 'f.id', '=', 'af.member_function_id')
                ->where('af.announcement_id', $announcementId)->whereNotNull('f.ext_location_id');
            $locationId === null
                ? ($visibleIds === [] ? $foreignFunctions : $foreignFunctions->whereNotIn('f.ext_location_id', $visibleIds))
                : $foreignFunctions->where('f.ext_location_id', '!=', $locationId);
            if ($foreignFunctions->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everyone it is meant for gets it by mail, under the bell and as push; with $only = ['database']
     * just the bell and push (a short notice, e.g. a changed time). A mail problem is logged, not thrown.
     *
     * @param  ?EloquentCollection  $to  only these (e.g. who hasn't confirmed yet)
     */
    public function mail(Announcement $announcement, ?array $only = null, ?EloquentCollection $to = null, bool $reminder = false, ?User $by = null): int
    {
        $this->assertActionAuthority($announcement, $by);
        if (! $this->isPublished($announcement)) {
            return 0;
        }

        $sent = 0;
        ($to ?? $this->recipients($announcement))->each(function (User $user) use ($announcement, $only, $reminder, &$sent) {
            try {
                $notification = new AnnouncementNotification($announcement, $reminder);
                $user->notify($only ? $notification->onlyVia($only) : $notification);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }

    /** Opened (the Mitteilungen page or an attachment): counted once */
    public function markRead(Announcement $announcement, User $user): void
    {
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $user->id, 'read_at' => now()]);
    }

    /** "Gelesen und verstanden" for a Mitteilung with Lesebestätigung */
    public function acknowledge(Announcement $announcement, User $user): void
    {
        $this->markRead($announcement, $user);
        DB::table('announcement_reads')->where('announcement_id', $announcement->id)->where('user_id', $user->id)->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);
    }

    /** @return array{recipients: int, read: int, acknowledged: int, missing: EloquentCollection} missing = not confirmed (or not read, without Lesebestätigung) */
    public function stats(Announcement $announcement, ?User $by = null): array
    {
        $this->assertActionAuthority($announcement, $by);
        $recipients = $this->recipients($announcement);
        $reads = DB::table('announcement_reads')->where('announcement_id', $announcement->id)->whereIn('user_id', $recipients->pluck('id'))->get()->keyBy('user_id');

        return [
            'recipients' => $recipients->count(), 'read' => $reads->count(), 'acknowledged' => $reads->whereNotNull('acknowledged_at')->count(),
            'missing' => $recipients->reject(fn (User $u) => $announcement->needs_ack ? isset($reads[$u->id]) && $reads[$u->id]->acknowledged_at : isset($reads[$u->id]))->values(),
        ];
    }

    /** Who hasn't confirmed yet is asked again (bell, push, mail) */
    public function remindUnacknowledged(Announcement $announcement, ?User $by = null): int
    {
        $this->assertActionAuthority($announcement, $by);
        if (! $this->isPublished($announcement)) {
            return 0;
        }

        return $this->mail($announcement, null, $this->stats($announcement, $by)['missing'], true, $by);
    }

    public function dismiss(Announcement $announcement, User $user): void
    {
        // One with Lesebestätigung stays until confirmed
        if (! $announcement->important && (! $announcement->needs_ack || $this->acknowledged($announcement, $user))) {
            $announcement->dismissedBy()->syncWithoutDetaching([$user->id]);
        }
    }

    public function acknowledged(Announcement $announcement, User $user): bool
    {
        return DB::table('announcement_reads')->where('announcement_id', $announcement->id)->where('user_id', $user->id)->whereNotNull('acknowledged_at')->exists();
    }
}
