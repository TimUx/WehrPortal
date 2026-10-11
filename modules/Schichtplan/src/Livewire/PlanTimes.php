<?php

namespace Modules\Schichtplan\src\Livewire;

use Illuminate\Support\Carbon;

/** Form inputs are Berlin times; an end before the start means the next day (e.g. 18:00–02:00) */
class PlanTimes
{
    /** @return array{0: Carbon, 1: Carbon} start and end in UTC */
    public static function fromInputs(string $date, string $start, string $end): array
    {
        $from = Carbon::parse($date.' '.$start, 'Europe/Berlin');
        $until = Carbon::parse($date.' '.$end, 'Europe/Berlin');

        if ($until->lte($from)) {
            $until->addDay();
        }

        return [$from->utc(), $until->utc()];
    }

    public static function local(?Carbon $time, string $format): string
    {
        return $time ? $time->copy()->setTimezone('Europe/Berlin')->format($format) : '';
    }

    public static function parseLocal(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value, 'Europe/Berlin')->utc() : null;
    }
}
