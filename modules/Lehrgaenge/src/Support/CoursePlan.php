<?php

namespace Modules\Lehrgaenge\src\Support;

use Illuminate\Support\Collection;
use Modules\Lehrgaenge\src\Models\Session;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/**
 * The Lehrgangsplan: every confirmed Lehrgangstag with time, place and the Kameraden on it. Kameraden
 * on the same Lehrgang at the same day, time and place share one row.
 */
class CoursePlan
{
    /** @return Collection<string, Collection<int, array{session: Session, course: string, people: Collection}>> by month ("2027-03") */
    public static function rows(?int $year = null): Collection
    {
        return Session::with(['record.course', 'record.user'])
            ->whereHas('record', fn ($q) => $q->whereIn('status', ['confirmed', 'completed']))
            ->when($year, fn ($q) => $q->whereYear('date', $year), fn ($q) => $q->whereDate('date', '>=', RegistrationService::today()))
            ->orderBy('date')->orderBy('starts_at')->get()
            ->groupBy(fn (Session $s) => implode('|', [$s->record->course_id, $s->date->toDateString(), $s->starts_at, $s->ends_at, mb_strtolower((string) $s->location)]))
            ->map(fn (Collection $same) => [
                'session' => $same->first(),
                'course' => $same->first()->record->course->name,
                'people' => $same->map(fn (Session $s) => $s->record->user)->filter()->unique('id')->sortBy('name')->values(),
            ])
            ->values()
            ->groupBy(fn (array $row) => $row['session']->date->format('Y-m'));
    }
}
