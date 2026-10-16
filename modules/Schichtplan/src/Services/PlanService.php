<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\Group;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Models\Slot;
use Modules\Schichtplan\src\Models\Station;
use Modules\Schichtplan\src\Models\Template;

/** Everything that changes a plan apart from the automatic distribution */
class PlanService
{
    public const MAX_SLOTS = 96;

    /**
     * @param  array{title: string, location?: ?string, starts_at: Carbon, ends_at: Carbon, shift_minutes: int, max_consecutive?: int, group_id?: ?int}  $data
     */
    public function create(array $data, ?User $by): Plan
    {
        $this->assertTimes($data['starts_at'], $data['ends_at'], $data['shift_minutes']);

        $data = $this->signup($this->utc($data));

        return DB::transaction(function () use ($data, $by) {
            $plan = Plan::create($data + ['created_by' => $by?->id]);
            $this->buildSlots($plan);

            return $plan;
        });
    }

    /** Changing the times or the shift length rebuilds the blocks, which clears the distribution */
    public function update(Plan $plan, array $data): bool
    {
        $this->assertTimes($data['starts_at'], $data['ends_at'], $data['shift_minutes']);

        $data = $this->signup($this->utc($data));

        return DB::transaction(function () use ($plan, $data) {
            $plan->fill($data);
            $rebuild = $plan->isDirty(['starts_at', 'ends_at', 'shift_minutes']);
            $plan->save();

            if ($rebuild) {
                $this->buildSlots($plan);
            }
            app(CalendarLink::class)->sync($plan);

            return $rebuild;
        });
    }

    public function buildSlots(Plan $plan): void
    {
        $plan->slots()->delete(); // assignments go with them

        $start = $plan->starts_at->copy();
        while ($start->lt($plan->ends_at)) {
            $end = $start->copy()->addMinutes($plan->shift_minutes)->min($plan->ends_at);
            $plan->slots()->create(['starts_at' => $start, 'ends_at' => $end]);
            $start = $end;
        }
    }

    public function addStation(Plan $plan, string $name, int $capacity = 1): Station
    {
        return $plan->stations()->create([
            'name' => trim($name),
            'capacity' => max(1, $capacity),
            'sort_order' => (int) $plan->stations()->max('sort_order') + 1,
        ]);
    }

    /** Fewer places drop the surplus people of the station, the hand-set ones last */
    public function updateStation(Station $station, string $name, int $capacity): void
    {
        DB::transaction(function () use ($station, $name, $capacity) {
            $station->update(['name' => trim($name), 'capacity' => max(1, $capacity)]);

            Assignment::where('station_id', $station->id)->get()->groupBy('slot_id')
                ->each(fn (Collection $seats) => $seats->sortBy('locked')->take(max(0, $seats->count() - $station->capacity))
                    ->each->delete());
        });
    }

    /** Stationsverantwortung: anyone with access to the Schichtplan, also someone with shifts; null removes it */
    public function setStationLead(Station $station, ?int $userId): void
    {
        $station->update(['lead_user_id' => $userId ?: null]);
        app(CalendarLink::class)->sync($station->plan()->first());
    }

    public function removeStation(Station $station): void
    {
        $station->delete();
    }

    public function applyTemplate(Plan $plan, Template $template): void
    {
        foreach ($template->stations as $station) {
            $this->addStation($plan, $station['name'], (int) ($station['capacity'] ?? 1));
        }
    }

    public function saveTemplate(Plan $plan, string $name): Template
    {
        return Template::updateOrCreate(['name' => trim($name)], [
            'stations' => $plan->stations()->get()->map(fn (Station $s) => ['name' => $s->name, 'capacity' => $s->capacity])->values()->all(),
        ]);
    }

    /** Only Kameraden who may use the module can take part */
    public function addParticipants(Plan $plan, iterable $userIds): int
    {
        $added = 0;

        User::whereIn('id', collect($userIds)->all())->active()->get()
            ->filter(fn (User $user) => $user->canUseModule('schichtplan'))
            ->each(function (User $user) use ($plan, &$added) {
                $added += $plan->participants()->firstOrCreate(['user_id' => $user->id])->wasRecentlyCreated ? 1 : 0;
            });
        app(CalendarLink::class)->sync($plan);

        return $added;
    }

    public function addGroup(Plan $plan, Group $group): int
    {
        return $this->addParticipants($plan, $group->users()->pluck('users.id'));
    }

    public function removeParticipant(Plan $plan, int $userId): void
    {
        DB::transaction(function () use ($plan, $userId) {
            $plan->assignments()->where('user_id', $userId)->delete();
            $plan->participants()->where('user_id', $userId)->delete();
        });
        app(CalendarLink::class)->sync($plan);
    }

    /** Assignments outside the new availability are dropped */
    public function setAvailability(Plan $plan, int $userId, ?Carbon $from, ?Carbon $until): void
    {
        if ($from && $until && $until->lte($from)) {
            throw ValidationException::withMessages(['availability' => '„Bis“ muss nach „ab“ liegen.']);
        }

        DB::transaction(function () use ($plan, $userId, $from, $until) {
            $participant = $plan->participants()->where('user_id', $userId)->firstOrFail();
            // Timestamps are stored as UTC; a Berlin time would otherwise be saved two hours off
            [$from, $until] = [$from?->copy()->utc(), $until?->copy()->utc()];
            $participant->update(['available_from' => $from, 'available_until' => $until]);

            $plan->assignments()->where('user_id', $userId)->with('slot')->get()
                ->reject(fn (Assignment $a) => $participant->isAvailableFor($a->slot))
                ->each->delete();
        });
    }

    /**
     * Puts a person on a place by hand (or empties it with null). If the person already works another
     * station in the same block, the two swap. Hand-set places are locked.
     */
    public function setSeat(Plan $plan, Slot $slot, Station $station, ?int $currentUserId, ?int $newUserId): void
    {
        DB::transaction(function () use ($plan, $slot, $station, $currentUserId, $newUserId) {
            $current = $currentUserId ? Assignment::where('slot_id', $slot->id)->where('station_id', $station->id)->where('user_id', $currentUserId)->first() : null;

            if ($newUserId === null) {
                $current?->delete();

                return;
            }

            if (! $plan->participants()->where('user_id', $newUserId)->exists()) {
                throw ValidationException::withMessages(['seat' => 'Die Person nimmt an diesem Plan nicht teil.']);
            }

            $elsewhere = Assignment::where('slot_id', $slot->id)->where('user_id', $newUserId)->first();

            if ($elsewhere && $elsewhere->station_id === $station->id) {
                $elsewhere->update(['locked' => true]);

                return;
            }

            if (! $current && $station->capacity <= Assignment::where('slot_id', $slot->id)->where('station_id', $station->id)->count()) {
                throw ValidationException::withMessages(['seat' => 'Die Station ist in diesem Block schon voll besetzt.']);
            }

            // Moving stations rather than people keeps "one place per person and block" true at every step
            if ($elsewhere && $current) {
                $otherStation = $elsewhere->station_id;
                $elsewhere->update(['station_id' => $station->id, 'locked' => true]);
                $current->update(['station_id' => $otherStation, 'locked' => true]);
            } elseif ($elsewhere) {
                $elsewhere->update(['station_id' => $station->id, 'locked' => true]);
            } elseif ($current) {
                $current->update(['user_id' => $newUserId, 'locked' => true]);
            } else {
                Assignment::create(['plan_id' => $plan->id, 'slot_id' => $slot->id, 'station_id' => $station->id, 'user_id' => $newUserId, 'locked' => true]);
            }
        });
    }

    public function toggleLock(Assignment $assignment): void
    {
        $assignment->update(['locked' => ! $assignment->locked]);
    }

    /**
     * Per person: shifts in total, per station, stations not had yet; and the empty places.
     *
     * @return array{people: Collection, gaps: Collection, min: int, max: int}
     */
    public function stats(Plan $plan): array
    {
        $plan->loadMissing(['stations', 'slots', 'participants.user']);
        $assignments = $plan->assignments()->get();

        $people = $plan->participants->map(function ($participant) use ($assignments, $plan) {
            $mine = $assignments->where('user_id', $participant->user_id);
            $had = $mine->pluck('station_id')->unique();

            return [
                'user' => $participant->user,
                'participant' => $participant,
                'total' => $mine->count(),
                'missing' => $plan->stations->reject(fn (Station $s) => $had->contains($s->id))->pluck('name')->values(),
            ];
        })->sortBy(fn ($p) => $p['user']->name)->values();

        $gaps = collect();
        foreach ($plan->slots as $slot) {
            foreach ($plan->stations as $station) {
                $missing = $station->capacity - $assignments->where('slot_id', $slot->id)->where('station_id', $station->id)->count();
                if ($missing > 0) {
                    $gaps->push(['slot' => $slot, 'station' => $station, 'missing' => $missing]);
                }
            }
        }

        return [
            'people' => $people,
            'gaps' => $gaps,
            'min' => (int) $people->min('total'),
            'max' => (int) $people->max('total'),
        ];
    }

    /**
     * The plan as CSV for Excel, laid out like the printed plan: one column per station, a row with the
     * Stationsverantwortung, one row per block; below that every person with their shifts.
     */
    public function csv(Plan $plan): string
    {
        $plan->load(['stations.lead', 'slots', 'participants.user']);
        $assignments = $plan->assignments()->with('user')->get();

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, [$plan->title, $plan->timeLabel(), (string) $plan->location], ';');
        Csv::put($out, [], ';');
        Csv::put($out, ['Zeit', ...$plan->stations->pluck('name')->all()], ';');
        Csv::put($out, ['Verantwortlich', ...$plan->stations->map(fn (Station $s) => $s->lead?->name ?? '')->all()], ';');

        foreach ($plan->slots as $slot) {
            Csv::put($out, [$slot->label(), ...$plan->stations->map(fn (Station $s) => $assignments
                ->where('slot_id', $slot->id)->where('station_id', $s->id)->map(fn (Assignment $a) => $a->user->name.($a->isRequested() ? ' (angefragt)' : ''))
                ->sort()->implode(', '))->all()], ';');
        }

        Csv::put($out, [], ';');
        Csv::put($out, ['Name', 'Schichten', 'Stationsverantwortung'], ';');
        $people = $plan->participants->pluck('user')->concat($plan->stations->pluck('lead'))->filter()->unique('id')->sortBy('name');

        foreach ($people as $user) {
            $shifts = $assignments->where('user_id', $user->id)
                ->sortBy(fn (Assignment $a) => $plan->slots->firstWhere('id', $a->slot_id)?->starts_at)
                ->map(fn (Assignment $a) => $plan->slots->firstWhere('id', $a->slot_id)?->label().' '.$plan->stations->firstWhere('id', $a->station_id)?->name);
            Csv::put($out, [$user->name, $shifts->implode(' · '), $plan->stations->where('lead_user_id', $user->id)->pluck('name')->implode(', ')], ';');
        }

        rewind($out);

        return stream_get_contents($out);
    }

    /** Self sign-up settings: mode, deadline (before the end), approval, limit per person, target group */
    private function signup(array $data): array
    {
        if (! array_key_exists('signup', $data)) {
            return $data;
        }
        if (! array_key_exists($data['signup'], Plan::SIGNUP_MODES)) {
            throw ValidationException::withMessages(['signup' => 'Bitte wählen, wer die Plätze besetzt.']);
        }
        $until = $data['signup_until'] ?? null;
        if ($until && $until->copy()->utc()->gt($data['ends_at'])) {
            throw ValidationException::withMessages(['signupUntil' => 'Die Eintragungsfrist muss vor dem Ende liegen.']);
        }
        $max = filled($data['max_shifts'] ?? null) ? (int) $data['max_shifts'] : null;
        if ($max !== null && ($max < 1 || $max > 50)) {
            throw ValidationException::withMessages(['maxShifts' => 'Bitte zwischen 1 und 50 Schichten je Person.']);
        }

        return [
            ...$data,
            'signup_until' => $until?->copy()->utc(),
            'needs_approval' => $data['signup'] === 'self' && (bool) ($data['needs_approval'] ?? false),
            'max_shifts' => $max,
            'audience_groups' => array_values(array_map('intval', $data['audience_groups'] ?? [])) ?: null,
            'audience_functions' => array_values(array_map('intval', $data['audience_functions'] ?? [])) ?: null,
        ];
    }

    private function utc(array $data): array
    {
        $data['starts_at'] = $data['starts_at']->copy()->utc();
        $data['ends_at'] = $data['ends_at']->copy()->utc();

        return $data;
    }

    private function assertTimes(Carbon $start, Carbon $end, int $minutes): void
    {
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        if ($minutes < 15 || $minutes > 720) {
            throw ValidationException::withMessages(['shift_minutes' => 'Die Schichtlänge muss zwischen 15 Minuten und 12 Stunden liegen.']);
        }

        if ($start->diffInMinutes($end) / $minutes > self::MAX_SLOTS) {
            throw ValidationException::withMessages(['shift_minutes' => 'Das ergibt zu viele Zeitblöcke. Wähle längere Schichten.']);
        }
    }
}
