<?php

namespace Extensions\EinsatzberichtPlus\Support;

/**
 * The expected crew of a vehicle from its Besatzung entry (Fahrzeuge, e.g. "1/8" or "1/2/6/9"). Two or three
 * numbers are Führer/Mannschaft(/…) and add up; with four or more the last one is the total when it equals
 * the sum of the others. Only a hint: nothing here blocks a report.
 */
class CrewSize
{
    public static function expected(?string $crew): ?int
    {
        if ($crew === null || ! preg_match_all('/\d+/', $crew, $m)) {
            return null;
        }

        $parts = array_map('intval', $m[0]);
        if (count($parts) === 1) {
            return $parts[0] > 0 ? $parts[0] : null;
        }

        $last = array_pop($parts);
        $total = count($parts) >= 2 && $last === array_sum($parts) ? $last : array_sum($parts) + $last;

        return $total > 0 ? $total : null;
    }

    /** @return array{state: string, text: string, tone: string} state: empty | under | ok | over */
    public static function check(int $present, ?int $expected): array
    {
        if ($expected === null) {
            return ['state' => 'unknown', 'text' => $present.' Einsatzkräfte (keine Sollbesatzung hinterlegt)', 'tone' => 'gray'];
        }
        if ($present === 0) {
            return ['state' => 'empty', 'text' => 'Noch keine Besatzung (Soll '.$expected.')', 'tone' => 'gray'];
        }
        if ($present < $expected) {
            return ['state' => 'under', 'text' => $present.' von '.$expected.' – unter Sollbesatzung ('.($expected - $present).' Plätze frei)', 'tone' => 'amber'];
        }
        if ($present > $expected) {
            return ['state' => 'over', 'text' => $present.' von '.$expected.' – über Sollbesatzung (+'.($present - $expected).'). Stimmt die Einteilung?', 'tone' => 'red'];
        }

        return ['state' => 'ok', 'text' => $present.' von '.$expected.' – Sollbesatzung erreicht', 'tone' => 'green'];
    }
}
