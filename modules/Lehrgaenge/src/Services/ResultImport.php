<?php

namespace Modules\Lehrgaenge\src\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;

/**
 * Lehrgangsergebnisse of an external Träger (Kreis, Landesfeuerwehrschule, FireCircle export …) as CSV: each row
 * is matched to a Kamerad (e-mail, or name in either order) and becomes an absolvierter Lehrgang – or an
 * Auffrischung of one with a refresher interval. First a preview, then "Übernehmen".
 */
class ResultImport
{
    private const ALIASES = [
        'email' => ['e-mail', 'email', 'mail', 'e-mail-adresse'],
        'name' => ['name', 'teilnehmer', 'teilnehmerin', 'teilnehmende', 'kamerad'],
        'first' => ['vorname'], 'last' => ['nachname', 'familienname'],
        'course' => ['lehrgang', 'kurs', 'lehrgangsbezeichnung', 'bezeichnung', 'course', 'veranstaltung'],
        'date' => ['datum', 'abschluss', 'abschlussdatum', 'bestanden am', 'ende', 'bis', 'enddatum', 'date'],
        'result' => ['ergebnis', 'status', 'bestanden', 'erfolg', 'result'],
    ];

    /**
     * @return list<array{line: int, name: string, course: string, date: ?string, user: ?User, action: string, label: string}>
     *                                                                                                                         action: new | refresh | known | no_user | no_date | failed
     */
    public function preview(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $csv), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['import' => 'Die Datei braucht eine Kopfzeile und mindestens eine Zeile.']);
        }
        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $delimiter));
        $col = [];
        foreach (self::ALIASES as $field => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $col[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($col['course']) || ! (isset($col['email']) || isset($col['name']) || isset($col['last']))) {
            throw ValidationException::withMessages(['import' => 'Die Kopfzeile braucht „Lehrgang“ und „E-Mail“ oder „Name“ (bzw. „Vorname“ und „Nachname“).']);
        }

        $users = User::where('status', '!=', 'left')->get(['id', 'name', 'email']);
        $rows = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = str_getcsv($line, $delimiter);
            $get = fn (string $f) => isset($col[$f]) ? trim((string) ($cells[$col[$f]] ?? '')) : '';
            $name = $get('name') !== '' ? $get('name') : trim($get('first').' '.$get('last'));
            $user = $this->match($users, $get('email'), $name);
            $course = $get('course');
            $date = $this->date($get('date'));
            $result = mb_strtolower($get('result'));
            $failed = $result !== '' && ! preg_match('/bestanden|erfolgreich|^ja$|^x$|^1$|teilgenommen|abgeschlossen/', $result) || str_contains($result, 'nicht');

            $action = match (true) {
                $failed => 'failed', ! $user => 'no_user', ! $date || $course === '' => 'no_date', default => $this->state($user, $course, $date),
            };
            $rows[] = ['line' => $n + 2, 'name' => $user?->name ?? ($name !== '' ? $name : $get('email')), 'course' => $course, 'date' => $date?->toDateString(), 'user' => $user,
                'action' => $action, 'label' => ['new' => 'wird eingetragen', 'refresh' => 'Auffrischung', 'known' => 'schon eingetragen', 'no_user' => 'Kamerad nicht gefunden',
                    'no_date' => 'Lehrgang oder Datum fehlt', 'failed' => 'nicht bestanden – übersprungen'][$action]];
        }

        return $rows;
    }

    /** @return int how many were taken over */
    public function apply(string $csv, string $source): int
    {
        $registrations = app(RegistrationService::class);
        $count = 0;
        foreach ($this->preview($csv) as $row) {
            if (in_array($row['action'], ['new', 'refresh'], true)) {
                $registrations->recordCompleted($row['user'], $row['course'], $row['date'], 'Import: '.mb_substr($source, 0, 100));
                $count++;
            }
        }

        return $count;
    }

    private function state(User $user, string $courseName, Carbon $date): string
    {
        $course = Course::whereRaw('lower(name) = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', $courseName)))])->first();
        $done = $course ? Record::where('user_id', $user->id)->where('course_id', $course->id)->where('status', 'completed')->first() : null;

        return match (true) {
            $done === null => 'new',
            $course->repeat_months && $done->completed_on && $date->gt($done->completed_on) => 'refresh',
            default => 'known',
        };
    }

    /** By e-mail, or by the name as "Vorname Nachname" or "Nachname, Vorname" */
    private function match($users, string $email, string $name): ?User
    {
        if ($email !== '' && ($u = $users->first(fn ($u) => mb_strtolower((string) $u->email) === mb_strtolower($email)))) {
            return $u;
        }
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        $candidates = array_unique([$norm($name), str_contains($name, ',') ? $norm(implode(' ', array_reverse(array_map('trim', explode(',', $name, 2))))) : '']);
        $found = $users->filter(fn ($u) => in_array($norm($u->name), $candidates, true));

        return $found->count() === 1 ? $found->first() : null;
    }

    private function date(string $value): ?Carbon
    {
        foreach (['!d.m.Y', '!Y-m-d', '!d.m.y'] as $format) {
            if (($d = rescue(fn () => Carbon::createFromFormat($format, $value), null, false)) && $d->year > 1950 && $d->lte(RegistrationService::today())) {
                return $d;
            }
        }

        return null;
    }
}
