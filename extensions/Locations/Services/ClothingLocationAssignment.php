<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicit maintenance repair of NULL tracked-item owners, never inference or reassignment.
 */
class ClothingLocationAssignment
{
    /** @return list<array{table: string, id: int, location_id: int, expected_location_id: null}> */
    public function readPlan(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Plan muss eine lesbare JSON-Datei sein.');
        }
        $json = file_get_contents($path, false, null, 0, 262145);
        if ($json === false || strlen($json) > 262144) {
            throw new RuntimeException('Plan darf höchstens 256 KiB groß sein.');
        }
        try {
            $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Ungültiges JSON im Plan.');
        }
        if (! str_starts_with(ltrim($json), '[') || ! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 500) {
            throw new RuntimeException('Plan muss eine Liste mit 1 bis 500 Zuordnungen sein.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 4
                || ($row['table'] ?? null) !== 'kk_items'
                || ! isset($row['id'], $row['location_id'])
                || ! array_key_exists('expected_location_id', $row) || $row['expected_location_id'] !== null
                || ! is_int($row['id']) || $row['id'] < 1
                || ! is_int($row['location_id']) || $row['location_id'] < 1) {
                throw new RuntimeException('Jede Zuordnung benötigt table: kk_items, positive ganzzahlige id/location_id und expected_location_id: null; keine weiteren Felder.');
            }
            if (isset($seen[$row['id']])) {
                throw new RuntimeException('Doppelte Datensatz-ID im Plan.');
            }
            $seen[$row['id']] = true;
        }
        usort($rows, fn (array $a, array $b) => $a['id'] <=> $b['id']);

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
        if (! config('locations.enabled') || DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Locations muss aktiviert sein; PostgreSQL ist erforderlich.');
        }
        foreach (['x_locations', 'x_location_user', 'users', 'kk_items', 'kk_issued_items', 'x_location_data_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Locations- und Kleiderkammer-Migrationen müssen installiert sein.');
            }
        }
        if (! Schema::hasColumn('kk_items', 'ext_location_id') || ! Schema::hasColumn('users', 'ext_location_id')) {
            throw new RuntimeException('Locations-Spalten müssen installiert sein.');
        }
        $optional = [];
        foreach (['kk_equipment_messages', 'kk_item_treatments'] as $table) {
            if (Schema::hasTable($table)) {
                $optional[] = $table;
            }
        }

        return DB::transaction(function () use ($plan, $apply, $by, $reason, $expect, $optional) {
            // Bound the maintenance window: prevent child/membership phantoms, including
            // writes which never lock the item parent. Payloads are streamed, never returned.
            DB::statement('LOCK TABLE '.implode(', ', ['x_location_user', 'kk_issued_items', ...$optional]).' IN SHARE MODE');
            $locations = [];
            $locationIds = array_values(array_unique(array_column($plan, 'location_id')));
            sort($locationIds, SORT_NUMERIC);
            foreach (array_chunk($locationIds, 200) as $ids) {
                foreach (DB::table('x_locations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $location) {
                    $locations[$location->id] = (array) $location;
                }
            }
            $items = [];
            foreach (array_chunk(array_column($plan, 'id'), 200) as $ids) {
                foreach (DB::table('kk_items')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $item) {
                    $items[$item->id] = (array) $item;
                }
            }
            // Parent locks hold off concurrent FK DDL until the repair commits.
            if ($apply) {
                $this->assertRestrictiveForeignKey();
            }
            $this->assertBoundedChildren(array_column($plan, 'id'), $optional);
            $context = hash_init('sha256');
            $this->digest($context, ['version' => 1, 'plan' => $plan, 'locations' => $locations,
                'operator' => ['by' => $by, 'reason' => $reason], 'optional_tables' => $optional]);
            $references = [];
            $summaries = [];
            foreach ($plan as $mapping) {
                $id = $mapping['id'];
                $locationId = $mapping['location_id'];
                $item = $items[$id] ?? null;
                if (! $item || $item['ext_location_id'] !== null || $item['deleted_at'] !== null) {
                    throw new RuntimeException("kk_items:{$id}: Datensatz fehlt, ist gelöscht oder bereits zugeordnet.");
                }
                if (! ($locations[$locationId]['is_active'] ?? false)) {
                    throw new RuntimeException("Standort {$locationId} fehlt oder ist inaktiv.");
                }
                $this->digest($context, ['item' => $item]);
                $open = 0;
                foreach (DB::table('kk_issued_items')->where('item_id', $id)->lazyById(1) as $issuance) {
                    $this->digest($context, ['issuance' => (array) $issuance]);
                    if ($issuance->returned_at === null && $issuance->deleted_at === null) {
                        $open++;
                        if ($issuance->user_id === null) {
                            throw new RuntimeException("kk_items:{$id}: Aktuelle Ausgabe ohne Empfänger.");
                        }
                        $references[(int) $issuance->user_id][$locationId] = true;
                    }
                }
                if ($open > 1) {
                    throw new RuntimeException("kk_items:{$id}: Mehrere offene Ausgaben; Daten zuerst prüfen.");
                }
                if (in_array('kk_equipment_messages', $optional, true)) {
                    foreach (DB::table('kk_equipment_messages')
                        ->whereIn('issued_item_id', DB::table('kk_issued_items')->select('id')->where('item_id', $id))
                        ->lazyById(1) as $message) {
                        $this->digest($context, ['message' => (array) $message]);
                    }
                }
                if (in_array('kk_item_treatments', $optional, true)) {
                    foreach (DB::table('kk_item_treatments')->where('item_id', $id)->lazyById(1) as $treatment) {
                        $this->digest($context, ['treatment' => (array) $treatment]);
                    }
                }
                $summaries[] = ['table' => 'kk_items', 'id' => $id, 'location_id' => $locationId, 'status' => 'ready_history_requires_review'];
            }
            $personIds = array_keys($references);
            if ($by !== null) {
                $personIds[] = $by;
            }
            $personIds = array_values(array_unique($personIds));
            sort($personIds, SORT_NUMERIC);
            $users = [];
            foreach (array_chunk($personIds, 200) as $ids) {
                foreach (DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
                    ->get(['id', 'ext_location_id', 'is_active', 'is_admin', 'status']) as $user) {
                    $users[$user->id] = (array) $user;
                }
            }
            if ($apply && (! ($users[$by]['is_active'] ?? false) || ! ($users[$by]['is_admin'] ?? false) || $users[$by]['status'] !== 'active')) {
                throw new RuntimeException('Nur ein aktiver globaler Administrator darf Zuordnungen anwenden.');
            }
            foreach ($personIds as $id) {
                $this->digest($context, ['person_id' => $id, 'person' => $users[$id] ?? null]);
                $memberships = [];
                foreach (DB::table('x_location_user')->where('user_id', $id)->lazyById(200, 'location_id') as $membership) {
                    $this->digest($context, ['membership' => (array) $membership]);
                    if (isset($references[$id][(int) $membership->location_id])) {
                        $memberships[(int) $membership->location_id] = true;
                    }
                }
                foreach (array_keys($references[$id] ?? []) as $locationId) {
                    if (! isset($users[$id]) || ((int) $users[$id]['ext_location_id'] !== $locationId && ! isset($memberships[$locationId]))) {
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
                    // Query builder intentionally avoids timestamps, model events and mail.
                    if (DB::table('kk_items')->where('id', $mapping['id'])->whereNull('ext_location_id')->whereNull('deleted_at')
                        ->update(['ext_location_id' => $mapping['location_id']]) !== 1) {
                        throw new RuntimeException('Zuordnung wurde zwischenzeitlich geändert.');
                    }
                    DB::table('x_location_data_assignments')->insert([
                        'target_table' => 'kk_items', 'target_id' => $mapping['id'], 'location_id' => $mapping['location_id'],
                        'assigned_by' => $by, 'reason' => $reason, 'assigned_at' => now(), 'batch_id' => $batch, 'state_hash' => $hash,
                    ]);
                }
                $summaries = array_map(fn (array $row) => [...$row, 'status' => 'assigned'], $summaries);
            }

            return ['hash' => $hash, 'batch_id' => $batch, 'rows' => $summaries];
        }, 3);
    }

    private function assertRestrictiveForeignKey(): void
    {
        $protected = DB::table('pg_constraint as c')
            ->join('pg_attribute as source', function ($join) {
                $join->on('source.attrelid', '=', 'c.conrelid')->where('source.attname', 'ext_location_id');
            })
            ->join('pg_attribute as target', function ($join) {
                $join->on('target.attrelid', '=', 'c.confrelid')->where('target.attname', 'id');
            })
            ->whereRaw("c.conrelid = 'kk_items'::regclass AND c.confrelid = 'x_locations'::regclass")
            ->whereRaw('c.conkey = ARRAY[source.attnum] AND c.confkey = ARRAY[target.attnum]')
            ->where('c.contype', 'f')->where('c.confdeltype', 'r')->where('c.confupdtype', 'r')
            ->where('c.convalidated', true)->where('c.condeferrable', false)->exists();
        if (! $protected) {
            throw new RuntimeException('Anwenden benötigt den validierten restriktiven Standort-Fremdschlüssel für kk_items. Locations-Migrationen und optionale Schema-Upgrades prüfen.');
        }
    }

    private function assertBoundedChildren(array $itemIds, array $optional): void
    {
        $children = DB::table('kk_issued_items')->whereIn('item_id', $itemIds)->count();
        if (in_array('kk_equipment_messages', $optional, true)) {
            $children += DB::table('kk_equipment_messages')
                ->whereIn('issued_item_id', DB::table('kk_issued_items')->select('id')->whereIn('item_id', $itemIds))->count();
        }
        if (in_array('kk_item_treatments', $optional, true)) {
            $children += DB::table('kk_item_treatments')->whereIn('item_id', $itemIds)->count();
        }
        if ($children > 10000) {
            throw new RuntimeException('Plan betrifft mehr als 10000 historische/aktuelle Ausgaben, Meldungen und Behandlungen; bitte kleinere Pläne verwenden.');
        }
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
