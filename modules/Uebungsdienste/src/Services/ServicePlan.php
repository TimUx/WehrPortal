<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Notifications\ResponsibleNotification;
use Throwable;

/**
 * Changes to the Übungsdienstplan. Date, time and place are planned at the start of the year and
 * only admins change them; topic and responsible people every Vorstand member.
 */
class ServicePlan
{
    public const MAX_SERIES = 60;

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /** The next Dienst from today on */
    public function next(): ?Service
    {
        return Service::with('responsible')->whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->first();
    }

    /** The next Übungsdienst for a Kamerad: one without target group, or of their Abteilung or Funktion (with sub-functions) */
    public function nextFor(User $user): ?Service
    {
        $groups = $user->groups()->pluck('groups.id')->all();
        $functions = $user->functions()->pluck('member_functions.id')->all();

        return Service::whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->get()
            ->first(function (Service $s) use ($groups, $functions) {
                $wantGroups = array_map('intval', $s->group_ids ?? []);
                $wantFunctions = MemberFunction::idsWithChildren(array_map('intval', $s->function_ids ?? []));

                return ($wantGroups === [] && $wantFunctions === []) || array_intersect($groups, $wantGroups) || array_intersect($functions, $wantFunctions);
            });
    }

    /**
     * @param  array{ends_at?: ?string, place_id?: ?int, dress_code_ids?: list<int>, group_ids?: list<int>, function_ids?: list<int>}  $details
     */
    public function add(User $by, string $date, ?string $time, ?string $location, array $details = []): Service
    {
        $this->assertAdmin($by);
        $service = Service::create($this->schedule($date, $time, $location, $details));
        app(CalendarLink::class)->sync($service, $by);

        return $service;
    }

    /** Every $weeks weeks from $from to $until, e.g. every second Tuesday */
    public function addSeries(User $by, string $from, string $until, int $weeks, ?string $time, ?string $location, array $details = []): Collection
    {
        $this->assertAdmin($by);
        $start = $this->date($from);
        $end = $this->date($until);

        if ($end->lt($start) || $weeks < 1) {
            throw ValidationException::withMessages(['series' => 'Bitte ein Enddatum nach dem Beginn und einen Abstand von mindestens einer Woche angeben.']);
        }

        $created = collect();
        for ($day = $start->copy(); $day->lte($end) && $created->count() < self::MAX_SERIES; $day->addWeeks($weeks)) {
            $created->push(Service::create($this->schedule($day->toDateString(), $time, $location, $details)));
        }
        $created->each(fn (Service $service) => app(CalendarLink::class)->sync($service, $by));

        return $created;
    }

    public function reschedule(User $by, Service $service, string $date, ?string $time, ?string $location, ?array $details = null): void
    {
        $this->assertAdmin($by);
        // Callers that don't know the details keep them
        $details ??= ['ends_at' => $service->ends_at, 'place_id' => $service->place_id, 'dress_code_ids' => $service->dress_code_ids ?? [],
            'group_ids' => $service->group_ids ?? [], 'function_ids' => $service->function_ids ?? []];
        $service->update($this->schedule($date, $time, $location, $details));
        app(CalendarLink::class)->sync($service->refresh(), $by);
    }

    public function delete(User $by, Service $service): void
    {
        $this->assertAdmin($by);
        app(CalendarLink::class)->remove($service);
        $service->delete();
    }

    public function setTopic(Service $service, ?string $topic): void
    {
        $topic = trim((string) $topic);

        if (mb_strlen($topic) > 1000) {
            throw ValidationException::withMessages(['topic' => 'Das Thema ist zu lang (höchstens 1000 Zeichen).']);
        }

        $service->update(['topic' => $topic !== '' ? $topic : null]);
    }

    /** Whoever is added to a coming Übungsdienst hears about it, unless they added themselves */
    public function addResponsible(Service $service, User $user, ?User $by = null): void
    {
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

    public function removeResponsible(Service $service, int $userId): void
    {
        $service->responsible()->detach($userId);
    }

    private function schedule(string $date, ?string $time, ?string $location, array $details = []): array
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
            'group_ids' => $ids('group_ids', Group::class) ?: null,
            'function_ids' => $ids('function_ids', MemberFunction::class) ?: null,
        ];
    }

    private function date(string $value): Carbon
    {
        return rescue(fn () => Carbon::createFromFormat('!Y-m-d', $value), null, false)
            ?? throw ValidationException::withMessages(['date' => 'Bitte ein gültiges Datum angeben.']);
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->is_admin) {
            throw ValidationException::withMessages(['date' => 'Datum, Uhrzeit und Ort ändern nur Admins.']);
        }
    }
}
