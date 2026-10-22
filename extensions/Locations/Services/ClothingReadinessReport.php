<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ClothingReadinessReport
{
    private const TABLES = [
        'kk_item_categories' => ['catalog', ['id']],
        'kk_item_types' => ['catalog', ['id']],
        'kk_sizes' => ['catalog', ['id']],
        'kk_item_groups' => ['catalog', ['id']],
        'kk_items' => ['direct_item', ['id', 'ext_location_id']],
        'kk_stock' => ['unresolved_quantity_stock', ['quantity', 'ext_location_id']],
        'kk_issued_items' => ['user_and_optional_item', ['id', 'user_id', 'item_id', 'returned_at', 'deleted_at']],
        'kk_member_sizes' => ['user', ['user_id']],
        'kk_equipment_messages' => ['user_and_optional_parent', ['user_id', 'issued_item_id']],
        'kk_item_treatments' => ['parent_item', ['item_id']],
    ];

    private const OPTIONAL = ['kk_member_sizes', 'kk_equipment_messages', 'kk_item_treatments'];

    private const JOURNALS = [
        'x_clothing_stock_transfers' => ['immutable_stock_transfer_journal', [
            'id', 'operation_key', 'from_stock_id', 'to_stock_id', 'from_location_id', 'to_location_id',
            'quantity', 'from_before', 'to_before', 'actor_id', 'reason', 'catalog', 'reverses_id', 'recorded_at',
        ]],
        'x_clothing_history_corrections' => ['private_documentary_source_review_journal', [
            'id', 'issuance_id', 'reviewed_stock_id', 'reviewed_location_id', 'actor_id',
            'previous_id', 'reason', 'evidence', 'state_hash', 'original', 'recorded_at',
        ]],
    ];

    public const FAILURE = 'Kleiderkammer-Prüfung nicht verfügbar: PostgreSQL, Schutzmanifest und erforderliches Schema prüfen. Es wurden keine Daten geändert.';

    public function report(): array
    {
        $connection = null;
        try {
            if (! config('locations.enabled')) {
                throw new RuntimeException;
            }
            // A separate connection leaves an existing caller transaction and its isolation untouched.
            $configuration = (new ConfigurationUrlParser)->parseConfiguration(
                config('database.connections.'.config('database.default')),
            );
            $connection = DB::build($configuration);
            if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql' || $connection->getTablePrefix() !== '') {
                throw new RuntimeException;
            }

            return $connection->transaction(function () use ($connection): array {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $available = $this->validate($connection);

                return $this->snapshot($connection, $available);
            });
        } catch (Throwable) {
            throw new RuntimeException(self::FAILURE);
        } finally {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }
        }
    }

    private function validate(Connection $connection): array
    {
        $schema = [];
        $builder = $connection->getSchemaBuilder();
        foreach ($builder->getTableListing() as $table) {
            $columns = $builder->getColumns($table);
            $schema[$table] = [
                'columns' => array_column($columns, 'name'),
                'nullable_columns' => array_column(array_filter($columns, fn (array $column): bool => $column['nullable']), 'name'),
                'foreign_keys' => $builder->getForeignKeys($table),
            ];
        }
        $manifest = config('locations.protection');
        if (! is_array($manifest)) {
            throw new RuntimeException;
        }
        $errors = app(ProtectionManifest::class)->violations($manifest, $schema);
        // These leaf tables are optional for this bounded report, not for the full protection audit.
        $allowed = array_map(fn (string $table): string => "Missing required application table: {$table}.", self::OPTIONAL);
        if (array_diff($errors, $allowed) !== []) {
            throw new RuntimeException;
        }

        $normalized = [];
        foreach ($schema as $table => $description) {
            $normalized[str($table)->afterLast('.')->toString()] = $description['columns'];
        }
        $required = [
            'users' => ['id', 'ext_location_id'],
            'x_locations' => ['id', 'is_active'],
            'x_location_user' => ['location_id', 'user_id'],
        ];
        foreach ([...self::TABLES, ...self::JOURNALS] as $table => [, $columns]) {
            if (($manifest['tables'][$table]['module'] ?? null) !== (isset(self::JOURNALS[$table]) ? 'extensions.locations' : 'kleiderkammer')) {
                throw new RuntimeException;
            }
            if (! isset($normalized[$table]) && in_array($table, self::OPTIONAL, true)) {
                continue;
            }
            $required[$table] = $columns;
        }
        foreach ($required as $table => $columns) {
            if (! isset($normalized[$table]) || array_diff($columns, $normalized[$table]) !== []) {
                throw new RuntimeException;
            }
        }

        return array_keys($normalized);
    }

    private function snapshot(Connection $connection, array $available): array
    {
        $tables = [];
        foreach ([...self::TABLES, ...self::JOURNALS] as $table => [$classification]) {
            $present = in_array($table, $available, true);
            $tables[] = [
                'table' => $table,
                'classification' => $classification,
                'availability' => $present ? 'available' : 'unavailable',
                'rows' => $present ? $connection->table($table)->count() : null,
            ];
        }

        $checks = [];
        $this->aggregate($connection, $checks, 'stock_transfer_journal', '
            SELECT count(*) AS entries,
                   count(*) FILTER (WHERE reverses_id IS NOT NULL) AS counterbookings
            FROM x_clothing_stock_transfers');
        $this->aggregate($connection, $checks, 'documentary_source_reviews', '
            SELECT count(*) AS entries, count(DISTINCT issuance_id) AS reviewed_issuances,
                   count(*) FILTER (WHERE previous_id IS NOT NULL) AS amendments
            FROM x_clothing_history_corrections');
        $this->aggregate($connection, $checks, 'items', '
            SELECT count(*) FILTER (WHERE i.ext_location_id IS NULL) AS null_location,
                   count(*) FILTER (WHERE l.is_active = false) AS inactive_location
            FROM kk_items i LEFT JOIN x_locations l ON l.id = i.ext_location_id');

        $membership = 'coalesce(u.ext_location_id = i.ext_location_id, false) OR membership.user_id IS NOT NULL';
        $issuanceJoins = 'FROM kk_issued_items a
            LEFT JOIN kk_items i ON i.id = a.item_id
            LEFT JOIN x_locations l ON l.id = i.ext_location_id
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN x_location_user membership ON membership.user_id = u.id AND membership.location_id = i.ext_location_id';
        $this->aggregate($connection, $checks, 'issuances', "
            SELECT count(*) FILTER (WHERE a.item_id IS NULL) AS quantity_or_unlinked_unresolved,
                   count(*) FILTER (WHERE a.item_id IS NOT NULL AND i.ext_location_id IS NULL) AS item_null_location,
                   count(*) FILTER (WHERE l.is_active = false) AS item_inactive_location,
                   count(*) FILTER (WHERE l.is_active AND NOT ({$membership})) AS membership_needs_review,
                   count(*) FILTER (WHERE l.is_active AND NOT ({$membership}) AND a.returned_at IS NULL AND a.deleted_at IS NULL) AS open_membership_needs_review
            {$issuanceJoins}");
        if ($connection->getSchemaBuilder()->hasColumn('kk_issued_items', 'ext_source_stock_id')) {
            $this->aggregate($connection, $checks, 'issuance_provenance', '
                SELECT count(*) FILTER (WHERE ext_source_stock_id IS NOT NULL) AS source_bound,
                       count(*) FILTER (WHERE ext_source_stock_id IS NULL AND item_id IS NULL) AS unsourced_bulk_or_unlinked
                FROM kk_issued_items');
        } else {
            $checks['issuance_provenance'] = ['availability' => 'unavailable', 'counts' => null];
        }

        $this->aggregate($connection, $checks, 'stock', '
            SELECT count(*) AS rows_unresolved, coalesce(sum(s.quantity), 0) AS quantity_unresolved,
                   count(*) FILTER (WHERE s.ext_location_id IS NULL) AS null_location,
                   count(*) FILTER (WHERE s.ext_location_id IS NOT NULL) AS assigned,
                   count(*) FILTER (WHERE l.is_active = false) AS inactive_location,
                   coalesce(sum(s.quantity) FILTER (WHERE s.ext_location_id IS NULL), 0) AS quantity_unassigned
            FROM kk_stock s LEFT JOIN x_locations l ON l.id = s.ext_location_id');

        if (in_array('kk_equipment_messages', $available, true)) {
            $this->aggregate($connection, $checks, 'messages', "
                SELECT count(*) FILTER (WHERE m.issued_item_id IS NULL) AS without_issuance_unresolved,
                       count(*) FILTER (WHERE a.id IS NOT NULL AND m.user_id <> a.user_id) AS user_issuance_mismatch_needs_review,
                       count(*) FILTER (WHERE a.id IS NOT NULL AND a.item_id IS NULL) AS linked_quantity_unresolved,
                       count(*) FILTER (WHERE a.item_id IS NOT NULL AND i.ext_location_id IS NULL) AS item_null_location,
                       count(*) FILTER (WHERE l.is_active = false) AS item_inactive_location,
                       count(*) FILTER (WHERE l.is_active AND NOT ({$membership})) AS membership_needs_review
                FROM kk_equipment_messages m
                LEFT JOIN kk_issued_items a ON a.id = m.issued_item_id
                LEFT JOIN kk_items i ON i.id = a.item_id
                LEFT JOIN x_locations l ON l.id = i.ext_location_id
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN x_location_user membership ON membership.user_id = u.id AND membership.location_id = i.ext_location_id");
        } else {
            $checks['messages'] = ['availability' => 'unavailable', 'counts' => null];
        }
        if (in_array('kk_item_treatments', $available, true)) {
            $this->aggregate($connection, $checks, 'treatments', '
                SELECT count(*) FILTER (WHERE i.ext_location_id IS NULL) AS parent_null_location,
                       count(*) FILTER (WHERE l.is_active = false) AS parent_inactive_location
                FROM kk_item_treatments t
                LEFT JOIN kk_items i ON i.id = t.item_id
                LEFT JOIN x_locations l ON l.id = i.ext_location_id');
        } else {
            $checks['treatments'] = ['availability' => 'unavailable', 'counts' => null];
        }

        $ownerTables = array_values(array_intersect(['kk_issued_items', 'kk_member_sizes', 'kk_equipment_messages'], $available));
        $owners = implode(' UNION ', array_map(fn (string $table): string => "SELECT user_id FROM {$table}", $ownerTables));
        $this->aggregate($connection, $checks, 'personal_owner_candidates', "
            WITH owners AS ({$owners}),
            memberships AS (
                SELECT id AS user_id, ext_location_id AS location_id FROM users WHERE ext_location_id IS NOT NULL
                UNION SELECT user_id, location_id FROM x_location_user
            ), active_memberships AS (
                SELECT m.user_id, count(*) AS candidates
                FROM memberships m JOIN x_locations l ON l.id = m.location_id AND l.is_active
                GROUP BY m.user_id
            )
            SELECT count(*) AS owners_unresolved,
                   count(*) FILTER (WHERE u.ext_location_id IS NULL) AS primary_null,
                   count(*) FILTER (WHERE l.is_active = false) AS primary_inactive,
                   count(*) FILTER (WHERE l.is_active) AS primary_active_potential,
                   count(*) FILTER (WHERE coalesce(am.candidates, 0) = 0) AS no_active_membership,
                   count(*) FILTER (WHERE am.candidates = 1) AS single_active_membership_potential,
                   count(*) FILTER (WHERE am.candidates > 1) AS multiple_active_memberships_potential
            FROM owners o LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN x_locations l ON l.id = u.ext_location_id
            LEFT JOIN active_memberships am ON am.user_id = o.user_id");

        return [
            'read_only' => true,
            'enforcement_verified' => false,
            'scope' => 'clothing_hybrid_readiness',
            'snapshot' => 'postgresql_repeatable_read_read_only',
            'row_scope' => 'all_rows_including_returned_and_soft_deleted',
            'review_policy' => 'needs_review_pending_business_approval_not_confirmed_violation',
            'automatic_target_inference' => false,
            'stock_adapter' => [
                'configured' => (bool) config('locations.clothing_stock_enabled', false),
                'coverage' => 'bounded_stock_listing_quantities_and_issue_return_targets',
                'grant' => 'kleiderkammer:bestand',
                'personal_authority' => 'separate_legacy_permission',
                'enforcement_verified' => false,
                'resolves_stock_rows' => false,
            ],
            'stock_operation_policy' => 'explicit_source_for_multiple_warehouses_source_bound_returns_exact_legacy_returns_only_sole_candidate_or_unowned_recreation_when_unprotected_no_inference_no_full_isolation_certification',
            'stock_transfer_policy' => 'explicit_existing_accounts_both_endpoint_rights_atomic_quantities_append_only_exact_counterbooking',
            'historical_review_policy' => 'closed_bulk_documentary_overlay_admin_preview_and_history_only_original_source_unchanged_no_automatic_stock_or_financial_balancing',
            'stock_global_uniqueness_preserved' => in_array('kk_stock', $available, true)
                ? collect($connection->getSchemaBuilder()->getIndexes('kk_stock'))
                    ->contains(fn (array $index): bool => $index['unique']
                        && $index['columns'] === ['item_type_id', 'size_id', 'group_id'])
                : null,
            'personal_owner_sources' => $ownerTables,
            'tables' => $tables,
            'checks' => $checks,
        ];
    }

    private function aggregate(Connection $connection, array &$checks, string $key, string $sql): void
    {
        $counts = array_map(fn ($count): int => (int) $count, (array) $connection->selectOne($sql));
        $checks[$key] = ['availability' => 'available', 'counts' => $counts];
    }
}
