<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\Place;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Notifications\ResponsibleNotification;
use Throwable;

/**
 * Changes to the Übungsdienstplan. The Wehrführung plans services for the Einsatzabteilung at
 * their Standort; admins can manage all Standorte.
 */
class ServicePlan
{
    public const MAX_SERIES = 60;

    private const GROUPS = ['einsatzabteilung', 'jugendfeuerwehr', 'kinderfeuerwehr'];

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /** The next Übungsdienst for any of the Kamerad's target groups */
    public function nextFor(User $user): ?Service
    {
        $groups = $user->groups()->pluck('groups.id')->all();

        return Service::whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->get()
            ->first(function (Service $s) use ($groups) {
                $wantGroups = array_map('intval', $s->group_ids ?? []);

                return array_intersect($groups, $wantGroups) !== [];
            });
    }

    /** Available target Standorte, restricted to the groups this planner may schedule. */
    public function locationOptions(User $user): Collection
    {
        if ($this->locationsEnabled()) {
            $ids = $this->plannerLocationIds($user);
            $groupIds = $this->groupOptions($user, $ids)->keys()->map(fn ($id) => (int) $id)->all();
            if ($groupIds === []) {
                return collect();
            }

            return Location::query()->active()->whereIn('id', $ids)
                ->whereHas('groups', fn ($query) => $query->withoutGlobalScopes()->whereIn('groups.id', $groupIds))
                ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
        }

        return $this->groupOptions($user, [])->mapWithKeys(fn ($name, $id) => [$id => $name]);
    }

    /** The member's primary Standort when available, otherwise the first permitted one. */
    public function defaultTargetIds(User $user): array
    {
        $options = $this->locationOptions($user);

        if ($this->locationsEnabled()) {
            $primary = (int) $user->getAttribute('ext_location_id');
            if ($options->has($primary)) {
                return [(string) $primary];
            }

            $current = app(LocationContext::class)->forUser($user)['current'];

            return $current !== null && $options->has($current)
                ? [(string) $current]
                : $options->keys()->take(1)->map(fn ($id) => (string) $id)->all();
        }

        return $options->keys()->take(1)->map(fn ($id) => (string) $id)->all();
    }

    /** Select one eligible group at each selected Standort as the initial form choice. */
    public function defaultGroupIds(User $user, array $locationIds): array
    {
        $options = $this->groupOptions($user, array_map('intval', $locationIds));
        $groups = Group::query()->withoutGlobalScope(LocationScope::class)
            ->whereIn('id', array_map('intval', $options->keys()->all()));
        $selected = [];

        foreach (array_map('intval', $locationIds) as $locationId) {
            $groupId = (clone $groups)->where('ext_location_id', $locationId)
                ->orderByRaw("CASE lower(name) WHEN 'einsatzabteilung' THEN 0 WHEN 'jugendfeuerwehr' THEN 1 ELSE 2 END")
                ->orderBy('sort_order')->value('id');
            if ($groupId) {
                $selected[] = (string) $groupId;
            }
        }

        if (! $this->locationsEnabled() && $selected === []) {
            $selected = $options->keys()->take(1)->map(fn ($id) => (string) $id)->all();
        }

        return $selected;
    }

    /** Location IDs represented by a service's selected groups. */
    public function targetIdsForGroups(array $groupIds, ?User $user = null): array
    {
        $allowedIds = $user ? array_intersect(array_map('intval', $groupIds), $this->targetGroupIds($user)) : array_map('intval', $groupIds);
        $query = $user ? Group::query()->withoutGlobalScope(LocationScope::class)->whereIn('id', $allowedIds) : Group::query();
        $groups = $query->whereIn('id', $allowedIds);

        return $this->locationsEnabled()
            ? $groups->pluck('ext_location_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all()
            : $groups->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** @param list<int> $locationIds @return Collection<int, string> group id => display name */
    public function groupOptions(User $user, array $locationIds): Collection
    {
        $names = $this->groupNamesFor($user);
        if ($names === [] || ($this->locationsEnabled() && $locationIds === [])) {
            return collect();
        }

        $standardLocations = [
            ...$user->standardRoleLocationIds('einsatzbericht', ['wehrführung']),
            ...$user->standardRoleLocationIds('uebungsdienste', ['wehrführung', 'jugendwart', 'gemeindejugendwart', 'kinderwart', 'gemeindekinderwart']),
        ];
        $groupsQuery = $this->groupsQuery($user);
        if ($standardLocations !== []) {
            $groupsQuery->withoutGlobalScope(LocationScope::class);
        }

        $groups = $groupsQuery
            ->whereIn(DB::raw('lower(name)'), $names)
            ->when($this->locationsEnabled(), fn ($query) => $query->whereIn('ext_location_id', $locationIds))
            ->with($this->locationsEnabled() ? 'location' : [])
            ->orderBy('sort_order')->orderBy('name')->get();

        if (! $user->hasModuleRole('einsatzbericht', 'wehrführung')) {
            if ($standardLocations !== []) {
                $groups = $groups->filter(fn (Group $group) => strtolower((string) $group->getAttribute('name')) !== 'einsatzabteilung'
                    || in_array((int) $group->getAttribute('ext_location_id'), $user->standardRoleLocationIds('einsatzbericht', ['wehrführung']), true));
            }
        }
        foreach ([
            'jugendfeuerwehr' => ['jugendwart', 'gemeindejugendwart'],
            'kinderfeuerwehr' => ['kinderwart', 'gemeindekinderwart'],
        ] as $groupName => $roles) {
            if (! collect($roles)->contains(fn (string $role) => $user->hasModuleRole('uebungsdienste', $role))) {
                $assigned = $user->standardRoleGroupIds('uebungsdienste', $roles);
                if ($assigned !== []) {
                    $groups = $groups->filter(fn (Group $group) => strtolower((string) $group->getAttribute('name')) !== $groupName
                        || in_array((int) $group->id, $assigned, true));
                }
            }
        }

        return $groups->mapWithKeys(fn (Group $group) => [(string) $group->id => $group->displayName()]);
    }

    /** Services for the groups and Standorte this planner may manage. */
    public function forPlanner(User $user, int $year): Collection
    {
        $groupIds = $this->targetGroupIds($user);

        return Service::with('responsible')->whereYear('date', $year)->orderBy('date')->orderBy('starts_at')->get()
            ->filter(fn (Service $service) => $user->is_admin || array_intersect($groupIds, array_map('intval', $service->group_ids ?? [])) !== [])
            ->values();
    }

    public function nextForPlanner(User $user): ?Service
    {
        $groupIds = $this->targetGroupIds($user);

        return Service::with('responsible')->whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->get()
            ->first(fn (Service $service) => $user->is_admin || array_intersect($groupIds, array_map('intval', $service->group_ids ?? [])) !== []);
    }

    public function yearsForPlanner(User $user): Collection
    {
        $groupIds = $this->targetGroupIds($user);

        return Service::query()->get(['date', 'group_ids'])
            ->filter(fn (Service $service) => $user->is_admin || array_intersect($groupIds, array_map('intval', $service->group_ids ?? [])) !== [])
            ->map(fn (Service $service) => $service->date->year)->unique()->sort()->values();
    }

    /**
     * @param  array{ends_at?: ?string, place_id?: ?int, dress_code_ids?: list<int>, target_location_ids?: list<int>, group_ids?: list<int>}  $details
     */
    public function add(User $by, string $date, ?string $time, ?string $location, array $details = []): Service
    {
        $this->assertPlanner($by);
        $service = Service::create($this->schedule($by, $date, $time, $location, $details));
        app(CalendarLink::class)->sync($service, $by);

        return $service;
    }

    /** Every $weeks weeks from $from to $until, e.g. every second Tuesday */
    public function addSeries(User $by, string $from, string $until, int $weeks, ?string $time, ?string $location, array $details = []): Collection
    {
        $this->assertPlanner($by);
        $start = $this->date($from);
        $end = $this->date($until);

        if ($end->lt($start) || $weeks < 1) {
            throw ValidationException::withMessages(['series' => 'Bitte ein Enddatum nach dem Beginn und einen Abstand von mindestens einer Woche angeben.']);
        }

        $created = collect();
        for ($day = $start->copy(); $day->lte($end) && $created->count() < self::MAX_SERIES; $day->addWeeks($weeks)) {
            $created->push(Service::create($this->schedule($by, $day->toDateString(), $time, $location, $details)));
        }
        $created->each(fn (Service $service) => app(CalendarLink::class)->sync($service, $by));

        return $created;
    }

    public function reschedule(User $by, Service $service, string $date, ?string $time, ?string $location, ?array $details = null): void
    {
        $this->assertCanManage($by, $service);
        // Callers that don't know the details keep them
        $details ??= ['ends_at' => $service->ends_at, 'place_id' => $service->place_id, 'dress_code_ids' => $service->dress_code_ids ?? [],
            'target_location_ids' => $this->targetIdsForGroups($service->group_ids ?? [], $by), 'group_ids' => $service->group_ids ?? []];
        $service->update($this->schedule($by, $date, $time, $location, $details));
        app(CalendarLink::class)->sync($service->refresh(), $by);
    }

    public function delete(User $by, Service $service): void
    {
        $this->assertCanManage($by, $service);
        app(CalendarLink::class)->remove($service);
        $service->delete();
    }

    public function setTopic(User $by, Service $service, ?string $topic): void
    {
        $this->assertCanManage($by, $service);
        $topic = trim((string) $topic);

        if (mb_strlen($topic) > 1000) {
            throw ValidationException::withMessages(['topic' => 'Das Thema ist zu lang (höchstens 1000 Zeichen).']);
        }

        $service->update(['topic' => $topic !== '' ? $topic : null]);
    }

    /** Whoever is added to a coming Übungsdienst hears about it, unless they added themselves */
    public function addResponsible(Service $service, User $user, User $by): void
    {
        $this->assertCanManage($by, $service);
        if (! $user->is_active) {
            throw ValidationException::withMessages(['responsible' => 'Nur aktive Kameraden können verantwortlich sein.']);
        }

        $added = $service->responsible()->syncWithoutDetaching([$user->id])['attached'] !== [];

        if ($added && ! $user->is($by) && $service->date->gte(self::today())) {
            try {
                $user->notify(new ResponsibleNotification($service, $by));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function removeResponsible(User $by, Service $service, int $userId): void
    {
        $this->assertCanManage($by, $service);
        $service->responsible()->detach($userId);
    }

    private function schedule(User $by, string $date, ?string $time, ?string $location, array $details = []): array
    {
        $time = trim((string) $time);
        $end = trim((string) ($details['ends_at'] ?? ''));
        $clock = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if ($time !== '' && ! preg_match($clock, $time)) {
            throw ValidationException::withMessages(['time' => 'Bitte die Uhrzeit als HH:MM angeben.']);
        }
        if ($end !== '' && (! preg_match($clock, $end) || $time === '')) {
            throw ValidationException::withMessages(['time' => 'Bitte das Ende als HH:MM angeben, und nur mit Beginn.']);
        }
        $targetIds = array_map('intval', $details['target_location_ids'] ?? $this->defaultTargetIds($by));
        $explicitGroups = array_key_exists('group_ids', $details);
        $fallbackIds = array_map('intval', $details['group_ids'] ?? ($this->locationsEnabled() ? [] : $targetIds));
        $groupIds = $this->selectedGroupIds($by, $targetIds, $fallbackIds, $explicitGroups);
        $ids = fn (string $key, string $class) => array_values(array_map('intval', $class::whereIn('id', array_map('intval', $details[$key] ?? []))->pluck('id')->all()));
        $place = filled($details['place_id'] ?? null) ? Place::find((int) $details['place_id']) : null;

        return [
            'date' => $this->date($date)->toDateString(),
            'starts_at' => $time !== '' ? $time : null,
            'ends_at' => $end !== '' ? $end : null,
            // A saved place gives the Ort its name
            'location' => $place?->name ?? (filled($location) ? mb_substr(trim($location), 0, 255) : null),
            'place_id' => $place?->id,
            'dress_code_ids' => $ids('dress_code_ids', DressCode::class) ?: null,
            'group_ids' => $groupIds,
            'function_ids' => null,
        ];
    }

    private function date(string $value): Carbon
    {
        return rescue(fn () => Carbon::createFromFormat('!Y-m-d', $value), null, false)
            ?? throw ValidationException::withMessages(['date' => 'Bitte ein gültiges Datum angeben.']);
    }

    private function assertPlanner(User $user): void
    {
        if (! $this->canPlan($user)) {
            throw ValidationException::withMessages(['date' => 'Für das Planen von Übungsdiensten ist eine passende Leitungsrolle erforderlich.']);
        }
    }

    public function canPlan(User $user): bool
    {
        return $user->is_active && ($user->is_admin || $this->groupNamesFor($user) !== []);
    }

    private function assertCanManage(User $user, Service $service): void
    {
        if (! $this->canManageService($user, $service)) {
            throw ValidationException::withMessages(['date' => 'Für diesen Standort darfst du den Übungsdienst nicht ändern.']);
        }
    }

    public function canManageService(User $user, Service $service): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }
        if (! $this->canPlan($user)) {
            return false;
        }

        $serviceGroups = array_map('intval', $service->group_ids ?? []);

        return $serviceGroups !== [] && array_diff($serviceGroups, $this->targetGroupIds($user)) === [];
    }

    /** @return list<int> */
    private function targetGroupIds(User $user): array
    {
        return $this->groupOptions($user, $this->locationsEnabled() ? $this->plannerLocationIds($user) : [])
            ->keys()->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function selectedGroupIds(User $by, array $targetIds, array $fallbackGroupIds, bool $explicit): array
    {
        if ($this->locationsEnabled()) {
            $allowed = $this->plannerLocationIds($by);
            $targetIds = array_values(array_unique($targetIds));

            if ($targetIds === [] || array_diff($targetIds, $allowed) !== []) {
                throw ValidationException::withMessages(['targetLocationIds' => 'Bitte nur Standorte auswählen, auf die du Zugriff hast.']);
            }

            $options = $this->groupOptions($by, $targetIds);
            $allowedGroupIds = array_map('intval', $options->keys()->all());

            if ($options->isEmpty()) {
                throw ValidationException::withMessages(['targetGroupIds' => 'Für die gewählten Standorte ist keine passende Abteilung vorhanden.']);
            }

            if (! $explicit) {
                $fallbackGroupIds = $this->defaultGroupIds($by, $targetIds);
            }
        } else {
            $allowedGroupIds = array_map('intval', $this->groupOptions($by, [])->keys()->all());
            if (! $explicit && $fallbackGroupIds === []) {
                $fallbackGroupIds = array_map('intval', $this->defaultTargetIds($by));
            }
        }

        $selected = array_values(array_unique($fallbackGroupIds));
        if ($selected === []) {
            throw ValidationException::withMessages(['targetGroupIds' => 'Bitte mindestens eine passende Abteilung auswählen.']);
        }
        if (array_diff($selected, $allowedGroupIds) !== []) {
            throw ValidationException::withMessages(['targetGroupIds' => 'Bitte nur Abteilungen auswählen, für die du zuständig bist.']);
        }

        return $selected;
    }

    /** @return list<string> lower-case group names the planner may schedule */
    private function groupNamesFor(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        if ($user->is_admin) {
            return self::GROUPS;
        }

        $names = [];
        if ($user->hasAnyModuleRole('einsatzbericht', 'wehrführung')) {
            $names[] = 'einsatzabteilung';
        }
        if ($user->hasAnyModuleRole('uebungsdienste', 'wehrführung')) {
            $names[] = 'einsatzabteilung';
        }
        if ($user->hasAnyModuleRole('uebungsdienste', 'jugendwart') || $user->hasAnyModuleRole('uebungsdienste', 'gemeindejugendwart')) {
            $names[] = 'jugendfeuerwehr';
        }
        if ($user->hasAnyModuleRole('uebungsdienste', 'kinderwart') || $user->hasAnyModuleRole('uebungsdienste', 'gemeindekinderwart')) {
            $names[] = 'kinderfeuerwehr';
        }

        return array_values(array_intersect(self::GROUPS, array_unique($names)));
    }

    /** @return list<int> */
    private function plannerLocationIds(User $user): array
    {
        if ($user->is_admin || $this->hasCrossLocationRole($user)) {
            return Location::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_values(array_unique([
            ...app(LocationAccess::class)->visibleLocationIds($user),
            ...$user->standardRoleLocationIds('einsatzbericht', ['wehrführung']),
            ...$user->standardRoleLocationIds('uebungsdienste', ['jugendwart', 'gemeindejugendwart', 'kinderwart', 'gemeindekinderwart']),
        ]));
    }

    private function hasCrossLocationRole(User $user): bool
    {
        return $user->canUseModule('uebungsdienste')
            && ($user->hasModuleRole('uebungsdienste', 'gemeindejugendwart') || $user->hasModuleRole('uebungsdienste', 'gemeindekinderwart'));
    }

    private function groupsQuery(User $user)
    {
        $query = Group::query();
        if ($user->is_admin || $this->hasCrossLocationRole($user)) {
            $query->withoutGlobalScope(LocationScope::class);
        }

        return $query;
    }

    public function locationsEnabled(): bool
    {
        return config('locations.enabled') && Schema::hasTable('x_locations');
    }
}
