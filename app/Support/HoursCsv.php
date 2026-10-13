<?php

namespace App\Support;

use App\Services\HoursRegistry;
use Illuminate\Support\Collection;

/** The Stundennachweis as CSV for Excel: one row per Dienst, Einsatz, Lehrgangstag or Schicht */
final class HoursCsv
{
    /** @param  array<int, string>  $names  user id → name */
    public static function make(Collection $rows, array $names): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Datum', 'Herkunft', 'Was', 'Stunden'], ';');
        foreach ($rows->sortBy(fn ($r) => $r['date']->timestamp) as $row) {
            fputcsv($out, [$names[$row['user_id']] ?? '', $row['date']->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'), $row['source'], $row['title'],
                str_replace(' h', '', HoursRegistry::format($row['minutes']))], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
