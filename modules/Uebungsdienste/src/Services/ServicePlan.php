<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Models\Location;
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

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /** The next Übungsdienst for a Kamerad in one of their Einsatzabteilungen */
    public function nextFor(User $user): ?Service
    {
        $groups = $user->groups()->pluck('groups.id')->all();

        return Service::whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->get()
            ->first(function (Service $s) use ($groups) {
                $wantGroups = array_map('intval', $s->group_ids ?? []);

                return array_intersect($groups, $wantGroups) !== [];
            });
    }

    /** Available target Standorte, restricted to those the planner may access and that have an Einsatzabteilung. */
    public function locationOptions(User $user): Collection
    {
        if ($this->locationsEnabled()) {
            $ids = app(LocationAccess::class)->visibleLocationIds($user);

            return Location::query()->active()->whereIn('id', $ids)
                ->whereHas('groups', fn ($query) => $query->whereRaw('lower(name) = ?', ['einsatzabteilung']))
                ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
        }

        return Group::query()->whereRaw('lower(name) = ?', ['einsatzabteilung'])
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
    }

    /** Standort selected by the user, or the first available Einsatzabteilung when Locations is disabled. */
    public function defaultTargetIds(User $user): array
    {
        $options = $this->locationOptions($user);

        if ($this->locationsEnabled()) {
            $current = app(LocationAccess::class)->currentLocationId($user);

            return $options->has($current) ? [(string) $current] : [];
        }

        return $options->keys()->take(1)->map(fn ($id) => (string) $id)->all();
    }

    /** Location IDs represented by a service's selected Einsatzabteilungen. */
    public function targetIdsForGroups(array $groupIds): array
    {
        $groups = Group::query()->whereIn('id', array_map('intval', $groupIds))
            ->whereRaw('lower(name) = ?', ['einsatzabteilung']);

        return $this->locationsEnabled()
            ? $groups->pluck('ext_location_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all()
            : $groups->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Services for the visible plan, limited to Einsatzabteilungen at the user's available Standorte. */
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
            'target_location_ids' => $this->targetIdsForGroups($service->group_ids ?? []), 'group_ids' => $service->group_ids ?? []];
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
        $fallbackIds = array_map('intval', $details['group_ids'] ?? ($this->locationsEnabled() ? [] : $targetIds));
        $groupIds = $this->selectedGroupIds($by, $targetIds, $fallbackIds);
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
        if (! $user->is_active || (! $user->is_admin && ! ($user->canUseModule('uebungsdienste') && $user->hasModuleRole('einsatzbericht', 'wehrführung')))) {
            throw ValidationException::withMessages(['date' => 'Übungsdienste planen nur die Wehrführung und Admins.']);
        }
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
        if (! $user->canUseModule('uebungsdienste') || ! $user->hasModuleRole('einsatzbericht', 'wehrführung')) {
            return false;
        }

        $serviceGroups = array_map('intval', $service->group_ids ?? []);

        return $serviceGroups !== [] && array_diff($serviceGroups, $this->targetGroupIds($user)) === [];
    }

    /** @return list<int> */
    private function targetGroupIds(User $user): array
    {
        if ($this->locationsEnabled()) {
            return Group::query()->whereRaw('lower(name) = ?', ['einsatzabteilung'])
                ->whereIn('ext_location_id', app(LocationAccess::class)->visibleLocationIds($user))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return Group::query()->whereRaw('lower(name) = ?', ['einsatzabteilung'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function selectedGroupIds(User $by, array $targetIds, array $fallbackGroupIds): array
    {
        if ($this->locationsEnabled()) {
            $allowed = app(LocationAccess::class)->visibleLocationIds($by);
            $targetIds = array_values(array_unique($targetIds));

            if ($targetIds === [] || array_diff($targetIds, $allowed) !== []) {
                throw ValidationException::withMessages(['targetLocationIds' => 'Bitte nur Standorte auswählen, auf die du Zugriff hast.']);
            }

            $groups = Group::query()->whereRaw('lower(name) = ?', ['einsatzabteilung'])
                ->whereIn('ext_location_id', $targetIds)->get(['id', 'ext_location_id']);
            $foundLocations = $groups->pluck('ext_location_id')->map(fn ($id) => (int) $id)->unique()->all();

            if (array_diff($targetIds, $foundLocations) !== []) {
                throw ValidationException::withMessages(['targetLocationIds' => 'Für jeden gewählten Standort muss eine Einsatzabteilung angelegt sein.']);
            }

            return $groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $groups = Group::query()->whereRaw('lower(name) = ?', ['einsatzabteilung'])
            ->whereIn('id', $fallbackGroupIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($groups === []) {
            throw ValidationException::withMessages(['targetLocationIds' => 'Bitte mindestens eine Einsatzabteilung auswählen.']);
        }

        return $groups;
    }

    public function locationsEnabled(): bool
    {
        return config('locations.enabled') && Schema::hasTable('x_locations');
    }
}
