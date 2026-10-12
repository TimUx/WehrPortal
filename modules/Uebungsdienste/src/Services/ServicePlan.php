<?php

namespace Modules\Uebungsdienste\src\Services;

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

    public function add(User $by, string $date, ?string $time, ?string $location): Service
    {
        $this->assertAdmin($by);

        return Service::create($this->schedule($date, $time, $location));
    }

    /** Every $weeks weeks from $from to $until, e.g. every second Tuesday */
    public function addSeries(User $by, string $from, string $until, int $weeks, ?string $time, ?string $location): Collection
    {
        $this->assertAdmin($by);
        $start = $this->date($from);
        $end = $this->date($until);

        if ($end->lt($start) || $weeks < 1) {
            throw ValidationException::withMessages(['series' => 'Bitte ein Enddatum nach dem Beginn und einen Abstand von mindestens einer Woche angeben.']);
        }

        $created = collect();
        for ($day = $start->copy(); $day->lte($end) && $created->count() < self::MAX_SERIES; $day->addWeeks($weeks)) {
            $created->push(Service::create($this->schedule($day->toDateString(), $time, $location)));
        }

        return $created;
    }

    public function reschedule(User $by, Service $service, string $date, ?string $time, ?string $location): void
    {
        $this->assertAdmin($by);
        $service->update($this->schedule($date, $time, $location));
    }

    public function delete(User $by, Service $service): void
    {
        $this->assertAdmin($by);
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

    private function schedule(string $date, ?string $time, ?string $location): array
    {
        $time = trim((string) $time);

        if ($time !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages(['time' => 'Bitte die Uhrzeit als HH:MM angeben.']);
        }

        return [
            'date' => $this->date($date)->toDateString(),
            'starts_at' => $time !== '' ? $time : null,
            'location' => filled($location) ? mb_substr(trim($location), 0, 255) : null,
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
