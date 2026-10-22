<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LocationRelationInventory
{
    public const FAILURE = 'Standort-Beziehungsinventur nicht verfügbar: PostgreSQL, Schutzmanifest und Schema prüfen. Es wurden keine Daten geändert.';

    public function report(): array
    {
        $connection = null;
        try {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration(
                config('database.connections.'.config('database.default')),
            );
            $connection = DB::build($configuration);
            if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql' || $connection->getTablePrefix() !== '') {
                throw new RuntimeException;
            }
            $connection->beginTransaction();
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $guard = app(ProtectionManifest::class);
            $schema = $guard->schema($connection);
            $manifest = config('locations.protection');
            if (! is_array($manifest) || $guard->violations($manifest, $schema) !== []) {
                throw new RuntimeException;
            }

            return $this->snapshot($connection, $manifest, $schema);
        } catch (Throwable) {
            // Never return SQL, bindings, manifest free text or exception payloads.
            throw new RuntimeException(self::FAILURE);
        } finally {
            if ($connection instanceof Connection) {
                try {
                    if ($connection->transactionLevel() > 0) {
                        $connection->rollBack();
                    }
                } catch (Throwable) {
                    throw new RuntimeException(self::FAILURE);
                } finally {
                    $connection->disconnect();
                }
            }
        }
    }

    private function snapshot(Connection $connection, array $manifest, array $schema): array
    {
        $available = [];
        foreach ($schema as $qualified => $description) {
            $name = trim((string) str($qualified)->afterLast('.'), '"');
            $available[$name] = ['qualified' => $qualified, 'columns' => $description['columns']];
        }
        $direct = [];
        foreach ($manifest['tables'] as $table => $definition) {
            foreach ($definition['parents'] as $edge) {
                if ($edge['table'] === 'x_locations' && $edge['kind'] !== 'polymorphic'
                    && in_array($edge['child_column'], $available[$table]['columns'] ?? [], true)) {
                    $direct[$table] = $edge['child_column'];
                    break;
                }
            }
        }

        $tables = $relations = $limitations = [];
        foreach ($manifest['tables'] as $table => $definition) {
            $present = isset($available[$table]);
            $column = $direct[$table] ?? null;
            $source = $present ? $connection->table($available[$table]['qualified'].' as c') : null;
            $counts = null;
            if ($source !== null && $column !== null) {
                $counts = ['null_ownership' => (clone $source)->whereNull('c.'.$column)->count()];
                $location = $available['x_locations'] ?? null;
                if ($location !== null && in_array('is_active', $location['columns'], true)) {
                    $edge = collect($definition['parents'])->firstWhere('child_column', $column);
                    $parent = $connection->table($location['qualified'].' as p')
                        ->whereColumn('p.'.$edge['column'], 'c.'.$column);
                    $counts['missing_location'] = (clone $source)->whereNotNull('c.'.$column)->whereNotExists(clone $parent)->count();
                    $counts['inactive_location'] = (clone $source)->whereExists((clone $parent)->where('p.is_active', false))->count();
                } else {
                    $counts['missing_location'] = $counts['inactive_location'] = null;
                    $limitations[] = ['table' => $table, 'category' => 'location_registry_not_inspected'];
                }
            }
            $tables[] = [
                'table' => $table, 'data_class' => $definition['data_class'],
                'availability' => $present ? 'available' : 'optional_absent_not_inspected',
                'extension_state' => isset($manifest['optional_extensions'][$definition['module']])
                    ? (config($manifest['optional_extensions'][$definition['module']]['config']) ? 'enabled' : 'disabled')
                    : 'not_optional_extension',
                'rows' => $source?->count(), 'location_column' => $column, 'counts' => $counts,
                'source_null_rule' => $column === null ? null : collect($definition['parents'])->firstWhere('child_column', $column)['source_null_rule'],
            ];
            if ($column === null) {
                $limitations[] = ['table' => $table, 'category' => 'non_direct_ownership_not_verified'];
            }
            if (count($definition['parents']) > 1) {
                $limitations[] = ['table' => $table, 'category' => 'multi_parent_policy_not_verified'];
            }
            foreach ($definition['polymorphic'] as $polymorphic) {
                $limitations[] = [
                    'table' => $table, 'category' => 'polymorphic_not_verified',
                    'type_column' => $polymorphic['type_column'], 'id_column' => $polymorphic['id_column'],
                ];
            }
            foreach ($definition['parents'] as $edge) {
                $relations[] = $this->relation($connection, $available, $direct, $table, $edge, $definition['data_class']);
            }
        }

        return [
            'read_only' => true,
            'scope' => 'finite_manifest_relations_readiness',
            'snapshot' => 'postgresql_repeatable_read_read_only',
            'enforcement_verified' => false,
            'automatic_target_inference' => false,
            'row_scope' => 'all_rows_including_historical_and_soft_deleted',
            'review_policy' => 'candidates_not_confirmed_violations',
            'edge_count_policy' => 'per_edge_counts_not_unique_affected_totals',
            'tables' => $tables,
            'relations' => $relations,
            'limitations' => $limitations,
        ];
    }

    private function relation(Connection $connection, array $available, array $direct, string $table, array $edge, string $dataClass): array
    {
        $result = [
            'table' => $table, 'child_column' => $edge['child_column'],
            'parent_table' => $edge['table'], 'parent_column' => $edge['column'],
            'kind' => $edge['kind'], 'source_null_rule' => $edge['source_null_rule'],
            'availability' => 'available', 'counts' => null,
            'ownership_comparison' => 'non_direct_not_verified',
        ];
        if ($edge['kind'] === 'polymorphic') {
            $result['availability'] = 'polymorphic_not_inspected';

            return $result;
        }
        if (! isset($available[$table], $available[$edge['table']])) {
            $result['availability'] = 'optional_schema_absent_not_inspected';

            return $result;
        }
        if (! in_array($edge['child_column'], $available[$table]['columns'], true)
            || ! in_array($edge['column'], $available[$edge['table']]['columns'], true)) {
            $result['availability'] = 'optional_column_absent_not_inspected';

            return $result;
        }
        $source = $connection->table($available[$table]['qualified'].' as c');
        $parent = $connection->table($available[$edge['table']]['qualified'].' as p')
            ->whereColumn('p.'.$edge['column'], 'c.'.$edge['child_column']);
        $counts = [
            'null_reference' => (clone $source)->whereNull('c.'.$edge['child_column'])->count(),
            'missing_parent' => (clone $source)->whereNotNull('c.'.$edge['child_column'])->whereNotExists(clone $parent)->count(),
            'parent_null_ownership' => null,
            'child_null_ownership' => null,
            'location_disagreement_review' => null,
        ];
        $childLocation = $direct[$table] ?? null;
        $parentLocation = $direct[$edge['table']] ?? null;
        if ($dataClass === 'audit') {
            $result['ownership_comparison'] = 'audit_actor_ownership_not_inferred';
        } else {
            if ($parentLocation !== null) {
                $counts['parent_null_ownership'] = $this->matched($source, $parent, fn (Builder $query) => $query->whereNull('p.'.$parentLocation));
            }
            if ($childLocation !== null) {
                $counts['child_null_ownership'] = (clone $source)->whereNull('c.'.$childLocation)->whereExists(clone $parent)->count();
            }
            if ($childLocation !== null && $parentLocation !== null) {
                $result['ownership_comparison'] = 'independent_direct_locations_review_only';
                $counts['location_disagreement_review'] = $this->matched($source, $parent, fn (Builder $query) => $query
                    ->whereNotNull('c.'.$childLocation)->whereNotNull('p.'.$parentLocation)
                    ->whereColumn('c.'.$childLocation, '<>', 'p.'.$parentLocation));
            }
        }
        $result['counts'] = $counts;

        return $result;
    }

    private function matched(Builder $source, Builder $parent, callable $constraint): int
    {
        $query = clone $parent;
        $constraint($query);

        // EXISTS counts source rows once even for non-unique logical parent keys.
        return (clone $source)->whereExists($query)->count();
    }
}
