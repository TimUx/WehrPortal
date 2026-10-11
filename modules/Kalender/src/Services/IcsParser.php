<?php

namespace Modules\Kalender\src\Services;

use DateInterval;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads iCalendar (RFC 5545) files as delivered by calendar URLs. Covers what such feeds use in
 * practice: single and all-day events, time zones, DURATION, cancelled events, and repetitions by
 * RRULE with FREQ, INTERVAL, COUNT and UNTIL (BYDAY and similar refinements are not evaluated),
 * EXDATE and moved single occurrences (RECURRENCE-ID).
 */
class IcsParser
{
    private const MAX_OCCURRENCES = 500;

    /**
     * @return array<int, array{uid: string, title: string, description: ?string, location: ?string, starts_at: Carbon, ends_at: Carbon, all_day: bool}>
     */
    public function parse(string $ics, Carbon $from, Carbon $until): array
    {
        $events = [];
        $overrides = [];

        foreach ($this->vevents($ics) as $props) {
            if (strtoupper($props['STATUS'][0]['value'] ?? '') === 'CANCELLED' || ! isset($props['DTSTART'])) {
                continue;
            }

            try {
                [$start, $allDay] = $this->date($props['DTSTART'][0]);
                $end = $this->end($props, $start, $allDay);
            } catch (Throwable) {
                continue; // a broken entry must not stop the whole feed
            }

            $base = [
                'uid' => $props['UID'][0]['value'] ?? md5(($props['SUMMARY'][0]['value'] ?? '').$start->toIso8601String()),
                'title' => $this->text($props['SUMMARY'][0]['value'] ?? '(ohne Titel)'),
                'description' => isset($props['DESCRIPTION']) ? $this->text($props['DESCRIPTION'][0]['value']) : null,
                'location' => isset($props['LOCATION']) ? $this->text($props['LOCATION'][0]['value']) : null,
                'all_day' => $allDay,
            ];

            if (isset($props['RECURRENCE-ID'])) {
                [$replaced] = $this->date($props['RECURRENCE-ID'][0]);
                $overrides[$base['uid']][$replaced->getTimestamp()] = true;
                $base['uid'] .= '#'.$replaced->format('Ymd\THis');
                $events[] = $base + ['starts_at' => $start, 'ends_at' => $end];

                continue;
            }

            if (! isset($props['RRULE'])) {
                $events[] = $base + ['starts_at' => $start, 'ends_at' => $end];

                continue;
            }

            $excluded = [];
            foreach ($props['EXDATE'] ?? [] as $exdate) {
                foreach (explode(',', $exdate['value']) as $value) {
                    try {
                        [$date] = $this->date(['value' => $value, 'params' => $exdate['params']]);
                        $excluded[$date->getTimestamp()] = true;
                    } catch (Throwable) {
                    }
                }
            }

            $duration = $start->diffInSeconds($end);
            foreach ($this->expand($start, $this->rrule($props['RRULE'][0]['value']), $until) as $occurrence) {
                if (isset($excluded[$occurrence->getTimestamp()])) {
                    continue;
                }

                $events[] = [
                    ...$base,
                    'uid' => $base['uid'].'#'.$occurrence->format('Ymd\THis'),
                    'recurrence_of' => $base['uid'],
                    'starts_at' => $occurrence,
                    'ends_at' => $occurrence->copy()->addSeconds($duration),
                ];
            }
        }

        return array_values(array_filter($events, function ($e) use ($from, $until, $overrides) {
            $moved = isset($e['recurrence_of']) && isset($overrides[$e['recurrence_of']][$e['starts_at']->getTimestamp()]);

            return ! $moved && $e['ends_at']->gt($from) && $e['starts_at']->lt($until);
        }));
    }

    /** @return array<int, array<string, array<int, array{value: string, params: array<string, string>}>>> */
    private function vevents(string $ics): array
    {
        // Unfold continuation lines (CRLF or LF followed by a space or tab)
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/\r?\n[ \t]/', '', $ics));
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
            } elseif ($line === 'END:VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;
            } elseif ($current !== null && preg_match('/^([A-Za-z0-9-]+)((?:;[^:]*)?):(.*)$/s', $line, $m)) {
                $params = [];
                foreach (array_filter(explode(';', $m[2])) as $param) {
                    [$key, $value] = array_pad(explode('=', $param, 2), 2, '');
                    $params[strtoupper($key)] = trim($value, '"');
                }
                $current[strtoupper($m[1])][] = ['value' => $m[3], 'params' => $params];
            }
        }

        return $events;
    }

    /** @return array{0: Carbon, 1: bool} start in UTC and whether it is a whole day */
    private function date(array $prop): array
    {
        $value = trim($prop['value']);
        $params = $prop['params'] ?? [];

        if (($params['VALUE'] ?? null) === 'DATE' || preg_match('/^\d{8}$/', $value)) {
            return [Carbon::createFromFormat('!Ymd', $value, 'Europe/Berlin')->utc(), true];
        }

        if (str_ends_with($value, 'Z')) {
            return [Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC'), false];
        }

        $zone = $params['TZID'] ?? 'Europe/Berlin';
        try {
            new \DateTimeZone($zone);
        } catch (Throwable) {
            $zone = 'Europe/Berlin'; // Windows names like "W. Europe Standard Time"
        }

        return [Carbon::createFromFormat('Ymd\THis', $value, $zone)->utc(), false];
    }

    private function end(array $props, Carbon $start, bool $allDay): Carbon
    {
        if (isset($props['DTEND'])) {
            [$end] = $this->date($props['DTEND'][0]);

            return $end->gt($start) ? $end : $start->copy()->addDay();
        }

        if (isset($props['DURATION'])) {
            return $start->copy()->add(new DateInterval(ltrim($props['DURATION'][0]['value'], '+')));
        }

        return $allDay ? $start->copy()->addDay() : $start->copy();
    }

    /** @return array{freq: string, interval: int, count: ?int, until: ?Carbon} */
    private function rrule(string $value): array
    {
        $parts = [];
        foreach (explode(';', $value) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $parts[strtoupper($key)] = $val;
        }

        $until = null;
        if (! empty($parts['UNTIL'])) {
            [$until] = $this->date(['value' => $parts['UNTIL'], 'params' => []]);
        }

        return [
            'freq' => strtoupper($parts['FREQ'] ?? ''),
            'interval' => max(1, (int) ($parts['INTERVAL'] ?? 1)),
            'count' => isset($parts['COUNT']) ? (int) $parts['COUNT'] : null,
            'until' => $until,
        ];
    }

    /** @return Carbon[] occurrences on the Berlin wall clock, so 19:00 stays 19:00 across DST */
    private function expand(Carbon $start, array $rule, Carbon $windowEnd): array
    {
        $local = $start->copy()->setTimezone('Europe/Berlin');
        $result = [];

        for ($i = 0; $i < self::MAX_OCCURRENCES; $i++) {
            $n = $i * $rule['interval'];
            $next = match ($rule['freq']) {
                'DAILY' => $local->copy()->addDays($n),
                'WEEKLY' => $local->copy()->addWeeks($n),
                'MONTHLY' => $local->copy()->addMonthsNoOverflow($n),
                'YEARLY' => $local->copy()->addYearsNoOverflow($n),
                default => null,
            };

            if ($next === null || ($rule['count'] !== null && $i >= $rule['count'])
                || ($rule['until'] !== null && $next->gt($rule['until'])) || $next->gt($windowEnd)) {
                break;
            }

            $result[] = $next->copy()->utc();
        }

        return $result ?: [$start];
    }

    private function text(string $value): string
    {
        return trim(strtr($value, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\;' => ';', '\\\\' => '\\']));
    }
}
