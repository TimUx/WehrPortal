<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Operator-reviewed repair of legacy NULL owners, not an ownership inference engine.
 */
class AttendanceLocationAssignment
{
    private const MAX_ROWS = 500;

    private const MAX_BYTES = 262144;

    /** @return list<array{table: string, id: int, location_id: int, expected_location_id: null}> */
    public function readPlan(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Plan muss eine lesbare JSON-Datei sein.');
        }
        $json = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Plan darf höchstens 256 KiB groß sein.');
        }
        try {
            $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Ungültiges JSON im Plan.');
        }
        if (! str_starts_with(ltrim($json), '[') || ! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Plan muss eine Liste mit 1 bis 500 Zuordnungen sein.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 4
                || ! isset($row['table'], $row['id'], $row['location_id'])
                || ! array_key_exists('expected_location_id', $row) || $row['expected_location_id'] !== null
                || ! in_array($row['table'], ['x_att_lists', 'x_att_templates'], true)
                || ! is_int($row['id']) || $row['id'] < 1
                || ! is_int($row['location_id']) || $row['location_id'] < 1) {
                throw new RuntimeException('Jede Zuordnung benötigt table (nur x_att_lists/x_att_templates), positive ganzzahlige id/location_id und expected_location_id: null.');
            }
            $key = $row['table'].':'.$row['id'];
            if (isset($seen[$key])) {
                throw new RuntimeException('Doppelte Datensatz-ID im Plan.');
            }
            $seen[$key] = true;
        }
        usort($rows, fn (array $a, array $b) => [$a['table'], $a['id']] <=> [$b['table'], $b['id']]);

        return $rows;
    }

    /** @return array{hash: string, batch_id: ?string, rows: list<array{table: string, id: int, location_id: int, status: string}>} */
    public function run(string $path, bool $apply = false, ?int $by = null, string $reason = '', ?string $expect = null, bool $backupConfirmed = false): array
    {
        $plan = $this->readPlan($path);
        $reason = trim($reason);
        if ($apply && (! $by || $reason === '' || mb_strlen($reason) > 500 || ! $backupConfirmed
            || ! is_string($expect) || preg_match('/\A[a-f0-9]{64}\z/', $expect) !== 1)) {
            throw new RuntimeException('Anwenden benötigt --by, --reason (1–500 Zeichen), --backup-confirmed und --expect (SHA256 der Vorschau). Die Backup-Angabe ist nur eine Bestätigung, keine Prüfung.');
        }
        foreach (['x_locations', 'x_location_user', 'x_att_lists', 'x_att_templates', 'x_att_participants', 'x_att_files', 'x_location_data_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Locations- und Anwesenheit-Migrationen müssen installiert sein.');
            }
        }

        return DB::transaction(function () use ($plan, $apply, $by, $reason, $expect) {
            // Child deletions/updates do not necessarily lock their parent. These short-lived
            // table locks also protect absent membership/participant rows against phantoms.
            DB::statement('LOCK TABLE x_location_user, x_att_participants, x_att_files IN SHARE MODE');
            $locations = [];
            $locationIds = array_values(array_unique(array_column($plan, 'location_id')));
            sort($locationIds, SORT_NUMERIC);
            foreach (array_chunk($locationIds, 200) as $ids) {
                foreach (DB::table('x_locations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $location) {
                    $locations[$location->id] = (array) $location;
                }
            }
            $records = [];
            $people = [];
            foreach (['x_att_lists', 'x_att_templates'] as $table) {
                $ids = array_column(array_filter($plan, fn (array $row) => $row['table'] === $table), 'id');
                foreach (array_chunk($ids, 200) as $chunk) {
                    foreach ($this->target($table)->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get() as $record) {
                        $records[$table][$record->id] = (array) $record;
                    }
                }
            }
            $context = hash_init('sha256');
            $this->digest($context, ['version' => 2, 'plan' => $plan, 'locations' => $locations,
                'operator' => ['by' => $by, 'reason' => $reason]]);
            $summaries = [];
            $references = [];
            foreach ($plan as $mapping) {
                $table = $mapping['table'];
                $id = $mapping['id'];
                $locationId = $mapping['location_id'];
                $record = $records[$table][$id] ?? null;
                if (! $record || $record['ext_location_id'] !== null) {
                    throw new RuntimeException("{$table}:{$id}: Datensatz fehlt oder ist bereits zugeordnet.");
                }
                if (! ($locations[$locationId]['is_active'] ?? false)) {
                    throw new RuntimeException("Standort {$locationId} fehlt oder ist inaktiv.");
                }
                $nullPerson = false;
                foreach ($table === 'x_att_lists' ? ['created_by', 'leader_id'] : ['leader_id'] as $column) {
                    if ($record[$column] === null) {
                        $nullPerson = true;
                    } else {
                        $people[(int) $record[$column]] = true;
                        $references[$locationId][(int) $record[$column]] = true;
                    }
                }
                $this->digest($context, ['table' => $table, 'record' => $record]);
                if ($table === 'x_att_lists') {
                    foreach (DB::table('x_att_participants')->where('list_id', $id)->orderBy('user_id')->lockForUpdate()->lazyById(200, 'user_id') as $participant) {
                        $people[(int) $participant->user_id] = true;
                        $references[$locationId][(int) $participant->user_id] = true;
                        $this->digest($context, ['participant' => (array) $participant]);
                        if (count($people) > 10000) {
                            throw new RuntimeException('Plan betrifft mehr als 10000 Personen; bitte kleinere Pläne verwenden.');
                        }
                    }
                    foreach (DB::table('x_att_files')->where('list_id', $id)->orderBy('id')->lockForUpdate()->lazyById(1) as $file) {
                        $this->digest($context, ['file' => (array) $file]);
                    }
                }
                $summaries[] = ['table' => $table, 'id' => $id, 'location_id' => $locationId,
                    'status' => $nullPerson ? 'ready_null_person_refs_require_review' : 'ready'];
            }
            $personIds = array_keys($people);
            if ($by !== null) {
                $personIds[] = $by;
            }
            $personIds = array_values(array_unique($personIds));
            sort($personIds, SORT_NUMERIC);
            $users = [];
            $memberships = [];
            foreach (array_chunk($personIds, 200) as $ids) {
                foreach (DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
                    ->get(['id', 'ext_location_id', 'is_active', 'is_admin', 'status']) as $user) {
                    $users[$user->id] = (array) $user;
                }
                foreach (DB::table('x_location_user')->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('location_id')->lockForUpdate()->get() as $membership) {
                    $memberships[$membership->user_id][] = (array) $membership;
                }
            }
            if ($apply && (! ($users[$by]['is_active'] ?? false) || ! ($users[$by]['is_admin'] ?? false) || $users[$by]['status'] !== 'active')) {
                throw new RuntimeException('Nur ein aktiver globaler Administrator darf Zuordnungen anwenden.');
            }
            $this->digest($context, ['operator_state' => $by === null ? null : ($users[$by] ?? null)]);
            $peopleIds = array_keys($people);
            sort($peopleIds, SORT_NUMERIC);
            foreach ($peopleIds as $id) {
                if (! isset($users[$id])) {
                    throw new RuntimeException("Person {$id} fehlt.");
                }
                $this->digest($context, ['person' => $users[$id], 'memberships' => $memberships[$id] ?? []]);
            }
            foreach ($references as $locationId => $ids) {
                foreach (array_keys($ids) as $id) {
                    if ((int) $users[$id]['ext_location_id'] !== $locationId
                        && ! in_array($locationId, array_column($memberships[$id] ?? [], 'location_id'), true)) {
                        throw new RuntimeException("Person {$id}: keine tatsächliche Mitgliedschaft in Standort {$locationId}.");
                    }
                }
            }
            $hash = hash_final($context);
            if ($apply && ! hash_equals($hash, $expect)) {
                throw new RuntimeException('Vorschau ist veraltet oder --by/--reason weichen ab. Plan mit denselben Operatorangaben erneut prüfen und neuen SHA256 verwenden.');
            }
            $batch = $apply ? (string) Str::uuid() : null;
            if ($apply) {
                foreach ($plan as $mapping) {
                    // Deliberately bypass model events: ownership only, no payload/children/mail.
                    if ($this->target($mapping['table'])->where('id', $mapping['id'])->whereNull('ext_location_id')
                        ->update(['ext_location_id' => $mapping['location_id']]) !== 1) {
                        throw new RuntimeException('Zuordnung wurde zwischenzeitlich geändert.');
                    }
                    DB::table('x_location_data_assignments')->insert([
                        'target_table' => $mapping['table'], 'target_id' => $mapping['id'], 'location_id' => $mapping['location_id'],
                        'assigned_by' => $by, 'reason' => $reason, 'assigned_at' => now(), 'batch_id' => $batch, 'state_hash' => $hash,
                    ]);
                }
                $summaries = array_map(fn (array $row) => [...$row, 'status' => 'assigned'], $summaries);
            }

            return ['hash' => $hash, 'batch_id' => $batch, 'rows' => $summaries];
        }, 3);
    }

    private function target(string $table): Builder
    {
        return match ($table) {
            'x_att_lists' => DB::table('x_att_lists'),
            'x_att_templates' => DB::table('x_att_templates'),
            default => throw new RuntimeException('Nicht unterstützte Zieltabelle.'),
        };
    }

    private function digest(\HashContext $context, array $value): void
    {
        $canonical = function (array $data) use (&$canonical): array {
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $data;
        };
        hash_update($context, json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
}
