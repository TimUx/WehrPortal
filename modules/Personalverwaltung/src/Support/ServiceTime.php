<?php

namespace Modules\Personalverwaltung\src\Support;

use App\Enums\MemberStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/**
 * The anrechenbare Dienstzeit from the Diensthistorie: the periods with status "Aktiv" plus Dienstzeiten in
 * other Wehren, minus Jugendfeuerwehr time the rules don't count (not at all, or only from an age). Without
 * any status history the Eintritt counts, as before. Years are calendar years, shifted by the gaps, so an
 * uninterrupted career has its anniversary on the day of the Eintritt.
 */
final class ServiceTime
{
    /** Open periods run up to this day when looking ahead (e.g. when an Ehrung will be reached) */
    private const HORIZON = '2200-01-01';

    /**
     * @return array{start: ?Carbon, years: int, months: int, counted: int, gaps: int, other: int, youth: int, notes: list<string>}|null
     *                                                                                                                                   null without any start
     */
    public static function on(User $user, ?Carbon $on = null): ?array
    {
        $on = self::day($on ?? PersonnelService::today());
        $parts = self::parts($user, $on);
        if ($parts['ranges'] === []) {
            return null;
        }
        $start = $parts['ranges'][0][0];
        $end = self::num($on) + 1;
        $counted = self::counted($parts['ranges'], $start, $end);
        $effective = self::date($start)->addDays($counted - 1);   // the last counted day, as if there had been no gaps
        $diff = self::date($start)->diff($effective);

        return ['start' => self::date($start), 'years' => $diff->y, 'months' => $diff->m, 'counted' => $counted, 'gaps' => max(0, $end - $start - $counted),
            'other' => $parts['other'], 'youth' => $parts['youth'], 'notes' => $parts['notes']];
    }

    public static function years(User $user, ?Carbon $on = null): ?int
    {
        return self::on($user, $on)['years'] ?? null;
    }

    /** The day on which the Kamerad reaches $years of Dienstzeit, if it keeps running as it is now */
    public static function reachedOn(User $user, int $years): ?Carbon
    {
        $ranges = self::parts($user, Carbon::parse(self::HORIZON))['ranges'];
        if ($ranges === []) {
            return null;
        }
        $start = self::date($ranges[0][0]);
        $target = $start->copy()->addYears($years);
        for ($i = 0; $i < 20; $i++) {
            $span = self::num($target) - $ranges[0][0];
            $gap = $span - self::counted($ranges, $ranges[0][0], self::num($target));
            $next = $start->copy()->addYears($years)->addDays($gap);
            if ($next->equalTo($target)) {
                break;
            }
            $target = $next;
        }

        return $target->year >= 2200 ? null : $target;
    }

    /** @return array{ranges: list<array{0: int, 1: int}>, other: int, youth: int, notes: list<string>} counted day ranges [from, to), merged */
    private static function parts(User $user, Carbon $until): array
    {
        $limit = self::num($until) + 1;
        $entries = HistoryEntry::where('user_id', $user->id)->whereIn('kind', ['status', 'service', 'group'])->get();
        $range = fn (HistoryEntry $e) => $e->since ? [self::num($e->since), min($limit, $e->until ? self::num($e->until) + 1 : $limit)] : null;

        $status = $entries->where('kind', 'status');
        $active = $status->isEmpty()
            ? ($user->joined_at ? [[self::num($user->joined_at), $limit]] : [])
            : $status->where('label', MemberStatus::Active->label())->map($range)->filter()->values()->all();
        $other = $entries->where('kind', 'service')->map($range)->filter()->values()->all();

        $notes = [];
        $minus = [];
        $rules = PersonnelService::serviceRules();
        if ($rules['youth_group_id'] && $rules['youth_rule'] !== 'full') {
            $youth = $entries->where('kind', 'group')->where('ref_id', $rules['youth_group_id'])->map($range)->filter();
            if ($rules['youth_rule'] === 'none') {
                $minus = $youth->values()->all();
            } elseif ($user->birth_date) {
                $cutoff = self::num($user->birth_date->copy()->addYears($rules['youth_age']));
                $minus = $youth->map(fn ($r) => [$r[0], min($r[1], $cutoff)])->filter(fn ($r) => $r[1] > $r[0])->values()->all();
            } elseif ($youth->isNotEmpty()) {
                $notes[] = 'Ohne Geburtsdatum zählt die Zeit in der Jugendfeuerwehr ganz.';
            }
        }

        $base = self::merge([...$active, ...$other]);
        $ranges = self::subtract($base, self::merge($minus));
        $all = $ranges === [] ? 0 : self::counted($ranges, $ranges[0][0], $limit);

        return [
            'ranges' => array_values(array_filter($ranges, fn ($r) => $r[0] < $limit)),
            'other' => $other === [] ? 0 : self::counted(self::merge($other), PHP_INT_MIN, $limit),
            'youth' => max(0, self::counted($base, PHP_INT_MIN, $limit) - $all),
            'notes' => $notes,
        ];
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private static function merge(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            if ($out && $from <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $to);
            } else {
                $out[] = [$from, $to];
            }
        }

        return $out;
    }

    private static function subtract(array $ranges, array $minus): array
    {
        foreach ($minus as [$mFrom, $mTo]) {
            $next = [];
            foreach ($ranges as [$from, $to]) {
                if ($mTo <= $from || $mFrom >= $to) {
                    $next[] = [$from, $to];

                    continue;
                }
                if ($mFrom > $from) {
                    $next[] = [$from, $mFrom];
                }
                if ($mTo < $to) {
                    $next[] = [$mTo, $to];
                }
            }
            $ranges = $next;
        }

        return $ranges;
    }

    private static function counted(array $ranges, int $from, int $to): int
    {
        return array_sum(array_map(fn ($r) => max(0, min($r[1], $to) - max($r[0], $from)), $ranges));
    }

    private static function day(Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString(), 'UTC');
    }

    private static function num(Carbon $date): int
    {
        return intdiv(self::day($date)->getTimestamp(), 86400);
    }

    private static function date(int $num): Carbon
    {
        return Carbon::createFromTimestamp($num * 86400, 'UTC')->startOfDay();
    }
}
