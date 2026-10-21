<?php

namespace App\Services;

use App\Models\User;
use Extensions\Locations\Services\LocationModuleRoles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a Kamerad that data hangs on (bookings, issued clothing, Einsätze …): everything that
 * points to them is handed to another Kamerad first, then they are deleted. The links are read
 * from the database (every foreign key to users that doesn't cascade), so new modules are covered
 * without changes here. Personal data that cascades (access, memberships, Lehrgänge, own
 * Rückmeldungen …) goes with them. Logs keep their history (the link is emptied, not moved), and
 * the Verbandbuch never moves health data to someone else: there the link is emptied, the names
 * stay in the entry as text.
 */
class MemberRemoval
{
    /** Left to the database (ON DELETE SET NULL): who did what in the past stays true */
    public const KEEP = [
        'activity_logs.user_id', 'activity_logs.impersonator_id', 'mail_logs.user_id', 'mail_logs.resent_by',
        'vb_access_logs.user_id', 'vb_signatures.user_id', 'vb_amendments.user_id', 'ex_events.user_id', 'fs_licenses.validated_by', 'fs_checks.checked_by', 'uw_sessions.instructor_id',
    ];

    /** Emptied instead of moved: entries about this person, with the name kept as text */
    public const UNLINK = ['vb_entries.injured_user_id', 'vb_entries.first_aider_user_id', 'vb_entries.recorded_by', 'vb_entries.reported_by', 'vb_entries.voided_by',
        // Expositionen are health data of this person: never moved, the name stays as text
        'ex_exposures.user_id', 'ex_exposures.recorded_by'];

    /** Table prefix → area, for the summary before deleting */
    public const AREAS = [
        'booking' => 'Fahrzeugbuchung', 'kk_' => 'Kleiderkammer', 'mm_' => 'Mängelmanagement', 'ar_' => 'Ausrüstung',
        'eb_' => 'Einsatzbericht', 'vb_' => 'Verbandbuch', 'ft_' => 'Fahrtenbuch', 'fin_' => 'Finanzen', 'wm_' => 'Wartung',
        'as_' => 'Atemschutz', 'fv_' => 'Fahrzeuge', 'wk_' => 'Wasserkarte', 'kal_' => 'Kalender', 'sp_' => 'Schichtplan',
        'lq_' => 'Lehrgänge', 'ex_' => 'Expositionen', 'fs_' => 'Führerscheine', 'uw_' => 'Unterweisungen', 'ud_' => 'Übungsdienste', 'announcement' => 'Mitteilungen', 'feedback' => 'Feedback',
    ];

    /**
     * Every column that points to users and would block the delete or be emptied by it.
     *
     * @return Collection<int, array{table: string, column: string}>
     */
    public function references(): Collection
    {
        return collect(DB::select(
            "SELECT c.conrelid::regclass::text AS tbl, a.attname AS col
             FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
             WHERE c.contype = 'f' AND c.confrelid = 'users'::regclass AND c.confdeltype IN ('a', 'r', 'n') AND c.conrelid <> 'users'::regclass
             ORDER BY 1, 2"
        ))->map(fn ($r) => ['table' => $r->tbl, 'column' => $r->col])
            ->reject(fn ($r) => $r['table'] === 'x_location_module_roles'
                || in_array($r['table'].'.'.$r['column'], self::KEEP, true));
    }

    /**
     * What would be handed over, per area: ['Fahrzeugbuchung' => 12, …]; the Verbandbuch counts
     * as handed over although its links are only emptied.
     *
     * @return array<string, int>
     */
    public function preview(User $user): array
    {
        $areas = [];
        foreach ($this->references() as $ref) {
            $count = DB::table($ref['table'])->where($ref['column'], $user->id)->count();
            if ($count > 0) {
                $area = self::area($ref['table']);
                $areas[$area] = ($areas[$area] ?? 0) + $count;
            }
        }
        ksort($areas);

        return $areas;
    }

    /**
     * Hands everything of $user to $to and deletes $user. All or nothing.
     *
     * @return array<string, int> what was handed over, per area
     */
    public function transferAndDelete(User $user, User $to, User $by): array
    {
        if ($user->is($to)) {
            throw ValidationException::withMessages(['to' => 'Bitte einen anderen Kameraden wählen, auf den übertragen wird.']);
        }
        if ($user->is($by)) {
            throw ValidationException::withMessages(['to' => 'Du kannst dich nicht selbst löschen.']);
        }

        $summary = $this->preview($user);

        DB::transaction(function () use ($user, $to) {
            User::whereKey($user->id)->lockForUpdate()->first();
            app(LocationModuleRoles::class)->assertMemberDeletable($user);

            foreach ($this->references() as $ref) {
                in_array($ref['table'].'.'.$ref['column'], self::UNLINK, true)
                    ? DB::table($ref['table'])->where($ref['column'], $user->id)->update([$ref['column'] => null])
                    : $this->move($ref['table'], $ref['column'], $user->id, $to->id);
            }

            // Not linked by a foreign key: the bell and the open sessions
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();
        });

        // "Fahrzeugbuchung: 12 → Bernd Blaulicht" in the log
        app(ActivityRecorder::class)->record('transferred', User::class, $user->id, $user->name.' → '.$to->name,
            collect($summary)->map(fn (int $count) => [$count.' Einträge', $to->name])->all());

        return $summary;
    }

    /**
     * Moves one column. Where the other Kamerad already has the same row (a unique key, e.g. both in
     * the crew of one Einsatz), the duplicate of the deleted one is dropped.
     */
    private function move(string $table, string $column, int $from, int $to): void
    {
        try {
            DB::transaction(fn () => DB::table($table)->where($column, $from)->update([$column => $to]));

            return;
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }

        foreach (DB::select("SELECT ctid::text AS ctid FROM {$this->quote($table)} WHERE {$this->quote($column)} = ?", [$from]) as $row) {
            try {
                DB::transaction(fn () => DB::update("UPDATE {$this->quote($table)} SET {$this->quote($column)} = ? WHERE ctid = ?::tid", [$to, $row->ctid]));
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                DB::delete("DELETE FROM {$this->quote($table)} WHERE ctid = ?::tid", [$row->ctid]);
            }
        }
    }

    private function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public static function area(string $table): string
    {
        foreach (self::AREAS as $prefix => $area) {
            if (str_starts_with($table, $prefix)) {
                return $area;
            }
        }

        return 'Allgemein';
    }
}
