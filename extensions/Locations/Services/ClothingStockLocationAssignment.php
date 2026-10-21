<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Explicit whole-row ownership metadata only; operational stock matching stays global.
 */
class ClothingStockLocationAssignment extends ClothingLocationAssignment
{
    protected const TARGET_TABLE = 'kk_stock';

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
        foreach (['x_locations', 'x_location_user', 'users', 'kk_stock', 'kk_item_types', 'kk_sizes', 'kk_item_groups', 'kk_issued_items', 'x_location_data_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Locations- und Kleiderkammer-Migrationen müssen installiert sein.');
            }
        }
        if (! Schema::hasColumn('kk_stock', 'ext_location_id') || ! Schema::hasColumn('users', 'ext_location_id')) {
            throw new RuntimeException('Locations-Spalten müssen installiert sein.');
        }
        $messagesAvailable = Schema::hasTable('kk_equipment_messages');

        return DB::transaction(function () use ($plan, $apply, $by, $reason, $expect, $messagesAvailable) {
            // Bulk issuances have no stock_id. Table locks prevent matching-key
            // phantoms, including historical writes and writes without parent locks.
            DB::statement('LOCK TABLE x_location_user, kk_issued_items'.($messagesAvailable ? ', kk_equipment_messages' : '').' IN SHARE MODE');
            DB::statement('LOCK TABLE x_location_data_assignments IN ROW EXCLUSIVE MODE');
            $locations = $this->lockedRows('x_locations', array_column($plan, 'location_id'));
            $stocks = $this->lockedRows('kk_stock', array_column($plan, 'id'));
            $catalog = [];
            foreach (['kk_item_types' => 'item_type_id', 'kk_sizes' => 'size_id', 'kk_item_groups' => 'group_id'] as $table => $column) {
                $catalog[$table] = $this->lockedRows($table, array_filter(array_column($stocks, $column), fn ($id) => $id !== null));
            }
            $operator = $by === null ? [] : $this->lockedRows('users', [$by]);
            if ($apply) {
                $this->assertRestrictiveForeignKey();
                $journal = DB::table('pg_constraint')->whereRaw("conrelid = 'x_location_data_assignments'::regclass")
                    ->where('conname', 'x_location_data_assignments_target')->where('contype', 'c')->where('convalidated', true)
                    ->selectRaw('pg_get_constraintdef(oid) AS definition')->first();
                if (! $journal || ! str_contains($journal->definition, "'kk_stock'::")) {
                    throw new RuntimeException('Anwenden benötigt die validierte Stock-Erweiterung des Zuordnungsjournals.');
                }
                $user = $operator[$by] ?? [];
                if (! ($user['is_active'] ?? false) || ! ($user['is_admin'] ?? false) || ($user['status'] ?? null) !== 'active') {
                    throw new RuntimeException('Nur ein aktiver globaler Administrator darf Zuordnungen anwenden.');
                }
            }
            $context = hash_init('sha256');
            // Private state is hashed only. No recipient can establish stock ownership.
            $this->digest($context, [
                'version' => 1, 'plan' => $plan, 'locations' => $locations, 'catalog' => $catalog,
                'operator' => ['by' => $by, 'reason' => $reason, 'state' => $operator],
                'messages_available' => $messagesAvailable,
            ]);
            if ($by !== null) {
                foreach (DB::table('x_location_user')->where('user_id', $by)->lazyById(200, 'location_id') as $membership) {
                    $this->digest($context, ['operator_membership' => (array) $membership]);
                }
            }
            $summaries = [];
            $children = 0;
            foreach ($plan as $mapping) {
                $id = $mapping['id'];
                $locationId = $mapping['location_id'];
                $stock = $stocks[$id] ?? null;
                if (! $stock || $stock['ext_location_id'] !== null) {
                    throw new RuntimeException("kk_stock:{$id}: Datensatz fehlt oder ist bereits zugeordnet.");
                }
                if (! is_int($stock['quantity']) || $stock['quantity'] < 0) {
                    throw new RuntimeException("kk_stock:{$id}: Menge muss nichtnegativ sein; nur ganze unveränderte Zeilen sind zuordenbar.");
                }
                if (! ($locations[$locationId]['is_active'] ?? false)) {
                    throw new RuntimeException("Standort {$locationId} fehlt oder ist inaktiv.");
                }
                $this->digest($context, ['stock' => $stock]);
                $issues = $this->matchingIssues($stock);
                $children += (clone $issues)->count();
                $messages = $messagesAvailable ? DB::table('kk_equipment_messages')
                    ->whereIn('issued_item_id', (clone $issues)->select('id')) : null;
                $children += $messages?->count() ?? 0;
                if ($children > 10000) {
                    throw new RuntimeException('Plan betrifft mehr als 10000 historische/aktuelle Ausgaben und Meldungen; bitte kleinere Pläne verwenden.');
                }
                foreach ((clone $issues)->lazyById(200) as $issue) {
                    if ($issue->returned_at === null && $issue->deleted_at === null) {
                        throw new RuntimeException("kk_stock:{$id}: Offene Mengenausgabe kk_issued_items:{$issue->id}; zunächst im bestehenden Ausgabe-/Rückgabeablauf fachlich klären. Keine automatische Rückgabe.");
                    }
                    $this->digest($context, ['issuance' => (array) $issue]);
                }
                if ($messages !== null) {
                    foreach ($messages->lazyById(200) as $message) {
                        $this->digest($context, ['message' => (array) $message]);
                    }
                }
                $summaries[] = ['table' => 'kk_stock', 'id' => $id, 'location_id' => $locationId, 'status' => 'ready_history_requires_review'];
            }
            $hash = hash_final($context);
            if ($apply && ! hash_equals($hash, $expect)) {
                throw new RuntimeException('Vorschau ist veraltet oder --by/--reason weichen ab. Plan mit denselben Operatorangaben erneut prüfen und neuen SHA256 verwenden.');
            }
            $batch = $apply ? (string) Str::uuid() : null;
            if ($apply) {
                foreach ($plan as $mapping) {
                    if (DB::table('kk_stock')->where('id', $mapping['id'])->whereNull('ext_location_id')
                        ->update(['ext_location_id' => $mapping['location_id']]) !== 1) {
                        throw new RuntimeException('Zuordnung wurde zwischenzeitlich geändert.');
                    }
                    DB::table('x_location_data_assignments')->insert([
                        'target_table' => 'kk_stock', 'target_id' => $mapping['id'], 'location_id' => $mapping['location_id'],
                        'assigned_by' => $by, 'reason' => $reason, 'assigned_at' => now(), 'batch_id' => $batch, 'state_hash' => $hash,
                    ]);
                }
                $summaries = array_map(fn (array $row) => [...$row, 'status' => 'assigned'], $summaries);
            }

            return ['hash' => $hash, 'batch_id' => $batch, 'rows' => $summaries];
        }, 3);
    }

    private function matchingIssues(array $stock): Builder
    {
        return DB::table('kk_issued_items')->whereNull('item_id')->where('item_type_id', $stock['item_type_id'])
            ->whereRaw('size_id IS NOT DISTINCT FROM ?', [$stock['size_id']])
            ->whereRaw('group_id IS NOT DISTINCT FROM ?', [$stock['group_id']]);
    }

    private function lockedRows(string $table, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get() as $row) {
                $rows[$row->id] = (array) $row;
            }
        }

        return $rows;
    }
}
