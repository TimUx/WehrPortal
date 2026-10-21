<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Support\Facades\Schema;

/**
 * Read-only schema/contract validation. A passing result says nothing about
 * enforcement: pending verification remains pending, including for child tables.
 */
class ProtectionManifest
{
    private const MODES = ['standortgebunden', 'übergreifend', 'hybrid', 'bewusst global'];

    private const DATA_CLASSES = ['access', 'audit', 'authentication', 'content', 'financial', 'financial_encrypted', 'health', 'health_encrypted', 'operational', 'personnel', 'personnel_encrypted', 'reference'];

    private const SOURCE_NULL_RULES = ['forbidden', 'shared', 'inherit', 'retain', 'alternative', 'root', 'deny_non_admin'];

    private const TARGET_NULL_RULES = ['deny_or_quarantine', 'explicitly_shared_catalog', 'not_applicable'];

    /**
     * @return array<string, array{columns: list<string>, nullable_columns: list<string>, foreign_keys: array}>
     */
    public function schema(): array
    {
        $schema = [];
        foreach (Schema::getTableListing() as $table) {
            $columns = Schema::getColumns($table);
            $schema[$table] = [
                'columns' => array_column($columns, 'name'),
                'nullable_columns' => array_column(array_filter($columns, fn (array $column): bool => $column['nullable']), 'name'),
                'foreign_keys' => Schema::getForeignKeys($table),
            ];
        }

        return $schema;
    }

    /**
     * The injectable snapshot keeps malformed-contract regression tests independent
     * of migrations. The audit test always uses the live PostgreSQL schema.
     *
     * @return list<string>
     */
    public function violations(array $manifest, ?array $snapshot = null): array
    {
        $errors = [];
        if (($manifest['version'] ?? null) !== 1
            || ($manifest['purpose'] ?? null) !== 'declarative_target_only'
            || ! $this->text($manifest['admin_rule'] ?? null)) {
            $errors[] = 'Manifest header must declare version 1, declarative_target_only and the preserved admin rule.';
        }
        $tables = $manifest['tables'] ?? null;
        $infrastructure = $manifest['infrastructure'] ?? null;
        if (! is_array($tables) || $tables === [] || ! is_array($infrastructure)) {
            return [...$errors, 'Manifest requires explicit tables and infrastructure maps.'];
        }
        $optionalTables = $this->optionalTables($manifest['optional_extensions'] ?? null, $tables, $errors);
        $schema = [];
        foreach ($snapshot ?? $this->schema() as $qualified => $description) {
            $name = $this->normalize((string) $qualified);
            if (isset($schema[$name])) {
                $errors[] = "Ambiguous normalized schema table: {$name}.";
            }
            if (! is_array($description) || ! is_array($description['columns'] ?? null) || ! is_array($description['foreign_keys'] ?? null)) {
                $errors[] = "Malformed schema snapshot: {$name}.";

                continue;
            }
            $schema[$name] = $description;
        }
        foreach ($infrastructure as $table => $reason) {
            if (! $this->identifier($table) || ! $this->text($reason)) {
                $errors[] = 'Infrastructure entries require a finite, unqualified table name and reason.';
            }
            if (array_key_exists($table, $tables)) {
                $errors[] = "Table appears in both application and infrastructure inventories: {$table}.";
            }
            if (! isset($schema[$table])) {
                $errors[] = "Missing infrastructure table: {$table}.";
            }
        }
        foreach (array_keys($schema) as $table) {
            if (! array_key_exists($table, $tables) && ! array_key_exists($table, $infrastructure)) {
                $errors[] = "Unclassified table: {$table}.";
            }
        }
        $retired = $manifest['retired_tables'] ?? null;
        if (! is_array($retired)) {
            $errors[] = 'Manifest requires an explicit retired_tables map for removed/renamed migration tables.';
        } else {
            foreach ($retired as $table => $retirement) {
                if (! $this->identifier($table) || ! is_array($retirement)
                    || ! $this->text($retirement['module'] ?? null)
                    || ! $this->reference($retirement['removed_by'] ?? null)
                    || ! $this->text($retirement['reason'] ?? null)) {
                    $errors[] = 'Malformed retired-table entry.';
                }
                if (array_key_exists($table, $tables) || array_key_exists($table, $infrastructure) || isset($schema[$table])) {
                    $errors[] = "Retired table must not be accepted as current: {$table}.";
                }
            }
        }
        foreach ($tables as $table => $entry) {
            if (! $this->identifier($table) || ! is_array($entry)) {
                $errors[] = 'Application entries require a finite, unqualified table name and an entry map.';

                continue;
            }
            $this->validateEntry($table, $entry, $tables, $schema, $optionalTables, $errors);
        }
        $this->validateParentChains($tables, $errors);

        sort($errors);

        return array_values(array_unique($errors));
    }

    private function optionalTables(mixed $extensions, array $tables, array &$errors): array
    {
        if (! is_array($extensions)) {
            $errors[] = 'Manifest requires explicit optional_extensions availability declarations.';

            return [];
        }
        $optional = [];
        foreach ($extensions as $module => $availability) {
            if (! is_string($module) || ! str_starts_with($module, 'extensions.')
                || ! is_array($availability)
                || ! $this->text($availability['config'] ?? null)
                || ! str_ends_with($availability['config'], '.enabled')
                || ! is_bool(config($availability['config']))
                || ! $this->reference($availability['provider'] ?? null)
                || ! $this->text($availability['reason'] ?? null)
                || ! is_array($availability['tables'] ?? null)
                || ! array_is_list($availability['tables'])
                || $availability['tables'] === []) {
                $errors[] = 'Malformed optional extension availability declaration.';

                continue;
            }
            foreach ($availability['tables'] as $table) {
                if (! $this->identifier($table)) {
                    $errors[] = 'Optional extension tables must be explicit, finite names.';

                    continue;
                }
                if (isset($optional[$table])) {
                    $errors[] = "{$table}: duplicate optional extension availability.";
                }
                if (! is_array($tables[$table] ?? null)
                    || ($tables[$table]['module'] ?? null) !== $module
                    || ($tables[$table]['optional_absence'] ?? null) !== $availability['reason']) {
                    $errors[] = "{$table}: optional availability must match the table module and documented absence reason.";
                }
                $optional[$table] = ! config($availability['config']);
            }
        }

        return $optional;
    }

    private function validateEntry(string $table, array $entry, array $tables, array $schema, array $optionalTables, array &$errors): void
    {
        $fields = ['module', 'mode', 'data_class', 'owner', 'parents', 'current_null_rule', 'null_rule', 'authorization', 'optional_absence', 'polymorphic', 'target_status', 'target_gap', 'enforcement'];
        if (array_diff($fields, array_keys($entry)) !== [] || array_diff(array_keys($entry), $fields) !== []) {
            $errors[] = "{$table}: missing or unknown entry fields.";
        }
        foreach (['module', 'owner', 'current_null_rule', 'target_gap'] as $field) {
            if (! $this->text($entry[$field] ?? null)) {
                $errors[] = "{$table}: invalid {$field}.";
            }
        }
        if (! in_array($entry['mode'] ?? null, self::MODES, true)) {
            $errors[] = "{$table}: invalid mode.";
        }
        if (! in_array($entry['data_class'] ?? null, self::DATA_CLASSES, true)) {
            $errors[] = "{$table}: invalid data_class.";
        }
        if (! in_array($entry['null_rule'] ?? null, self::TARGET_NULL_RULES, true)) {
            $errors[] = "{$table}: invalid null_rule target decision.";
        }
        if (! in_array($entry['target_status'] ?? null, ['proposed', 'pending_decision'], true)) {
            $errors[] = "{$table}: target_status must be proposed or pending_decision, not approval or enforcement certification.";
        }
        if (in_array($entry['data_class'] ?? null, ['access', 'authentication', 'personnel', 'personnel_encrypted', 'health', 'health_encrypted', 'financial_encrypted'], true)
            && ($entry['mode'] ?? null) !== 'bewusst global'
            && ($entry['null_rule'] ?? null) !== 'deny_or_quarantine') {
            $errors[] = "{$table}: sensitive/personnel NULL ownership must target deny_or_quarantine, not shared access.";
        }
        $optional = $entry['optional_absence'] ?? null;
        if ($optional !== null && ! $this->text($optional)) {
            $errors[] = "{$table}: optional_absence requires an explicit reason or NULL.";
        }
        if ($optional !== null && ! array_key_exists($table, $optionalTables)) {
            $errors[] = "{$table}: optional absence must belong to a documented optional extension table allowlist.";
        }
        if (! isset($schema[$table]) && (! $this->text($optional) || ! ($optionalTables[$table] ?? false))) {
            $errors[] = "Missing required application table: {$table}.";
        }
        $authorization = $entry['authorization'] ?? null;
        if (! is_array($authorization) || ! array_is_list($authorization) || $authorization === []) {
            $errors[] = "{$table}: authorization must name existing mechanism files.";
        } else {
            foreach ($authorization as $reference) {
                if (! $this->reference($reference)) {
                    $errors[] = "{$table}: invalid authorization reference.";
                }
            }
        }
        $enforcement = $entry['enforcement'] ?? null;
        if (! is_array($enforcement)
            || array_diff(['status', 'evidence', 'gap'], array_keys($enforcement)) !== []
            || array_diff(array_keys($enforcement), ['status', 'evidence', 'gap']) !== []
            || ! in_array($enforcement['status'] ?? null, ['pending_verification', 'implemented'], true)
            || ! is_array($enforcement['evidence'] ?? null)
            || ! $this->text($enforcement['gap'] ?? null)) {
            $errors[] = "{$table}: enforcement must separately record status, evidence and remaining gap.";
        } else {
            if ($enforcement['status'] === 'implemented' && $enforcement['evidence'] === []) {
                $errors[] = "{$table}: implemented enforcement requires verification evidence.";
            }
            foreach ($enforcement['evidence'] as $evidence) {
                if (! $this->reference($evidence)) {
                    $errors[] = "{$table}: invalid enforcement evidence reference.";
                }
            }
        }
        $parents = $entry['parents'] ?? null;
        if (! is_array($parents) || ! array_is_list($parents)) {
            $errors[] = "{$table}: parents must be an explicit list (possibly empty for an explained owner).";
        } else {
            if ($parents === [] && in_array($entry['mode'] ?? null, ['standortgebunden', 'hybrid'], true)
                && ($entry['target_status'] ?? null) !== 'pending_decision') {
                $errors[] = "{$table}: location-bound/hybrid entries require an explicit parent or owner relation.";
            }
            foreach ($parents as $parent) {
                $this->validateParent($table, $parent, $tables, $schema, $errors);
            }
        }
        $polymorphic = $entry['polymorphic'] ?? null;
        if (! is_array($polymorphic) || ! array_is_list($polymorphic)) {
            $errors[] = "{$table}: polymorphic must be an explicit list.";
        } else {
            foreach ($polymorphic as $relation) {
                if (! is_array($relation)
                    || ! $this->identifier($relation['type_column'] ?? null)
                    || ! $this->identifier($relation['id_column'] ?? null)
                    || ! $this->text($relation['rule'] ?? null)
                    || array_diff(array_keys($relation), ['type_column', 'id_column', 'rule']) !== []) {
                    $errors[] = "{$table}: malformed polymorphic relation.";

                    continue;
                }
                foreach (['type_column', 'id_column'] as $field) {
                    if (isset($schema[$table]) && ! in_array($relation[$field], $schema[$table]['columns'], true)) {
                        $errors[] = "{$table}: missing polymorphic column {$relation[$field]}.";
                    }
                }
            }
        }
    }

    private function validateParent(string $table, mixed $parent, array $tables, array $schema, array &$errors): void
    {
        $fields = ['child_column', 'table', 'column', 'kind', 'source_null_rule', 'optional_column', 'absence_reason'];
        if (! is_array($parent)
            || array_diff($fields, array_keys($parent)) !== []
            || array_diff(array_keys($parent), $fields) !== []
            || ! $this->identifier($parent['child_column'] ?? null)
            || ! $this->identifier($parent['table'] ?? null)
            || ! $this->identifier($parent['column'] ?? null)
            || ! in_array($parent['kind'] ?? null, ['foreign_key', 'conditional_foreign_key', 'logical', 'conditional_logical', 'historical', 'polymorphic'], true)
            || ! in_array($parent['source_null_rule'] ?? null, self::SOURCE_NULL_RULES, true)
            || ! is_bool($parent['optional_column'] ?? null)) {
            $errors[] = "{$table}: malformed parent relation (child_column, table, column, kind and source NULL rule required).";

            return;
        }
        $column = $parent['child_column'];
        $target = $parent['table'];
        if ($parent['optional_column'] && (! in_array($parent['kind'], ['conditional_foreign_key', 'conditional_logical'], true) || ! $this->text($parent['absence_reason']))) {
            $errors[] = "{$table}.{$column}: optional column requires a conditional relation and explicit absence reason.";
        } elseif (! $parent['optional_column'] && $parent['absence_reason'] !== null) {
            $errors[] = "{$table}.{$column}: required column cannot declare an absence reason.";
        }
        if (! array_key_exists($target, $tables)) {
            $errors[] = "{$table}.{$column}: unclassified parent table {$target}.";
        }
        if (isset($schema[$target])) {
            if (! in_array($parent['column'], $schema[$target]['columns'], true)) {
                $errors[] = "{$table}.{$column}: missing parent column {$target}.{$parent['column']}.";
            }
        } elseif (isset($schema[$table]) && (! in_array($parent['kind'], ['conditional_foreign_key', 'conditional_logical', 'historical'], true)
            || ! $this->text($tables[$target]['optional_absence'] ?? null))) {
            $errors[] = "{$table}.{$column}: missing parent table {$target}.";
        }
        if (! isset($schema[$table])) {
            return;
        }
        if (! in_array($column, $schema[$table]['columns'], true)) {
            // This optional extension column points to a core table that also
            // exists on older installations without the provenance migration.
            $optionalStockSource = $table === 'kk_issued_items' && $column === 'ext_source_stock_id' && $target === 'kk_stock';
            if (! $parent['optional_column'] || (isset($schema[$target]) && ! $optionalStockSource)) {
                $errors[] = "{$table}: missing child parent column {$column}.";
            }

            return;
        }
        if (isset($schema[$table]['nullable_columns'])
            && in_array($column, $schema[$table]['nullable_columns'], true) === ($parent['source_null_rule'] === 'forbidden')) {
            $errors[] = "{$table}.{$column}: source NULL rule contradicts schema nullability.";
        }
        $requiresForeignKey = $parent['kind'] === 'foreign_key'
            || ($parent['kind'] === 'conditional_foreign_key' && isset($schema[$target]));
        if ($requiresForeignKey && ! collect($schema[$table]['foreign_keys'])->contains(
            fn (array $fk): bool => ($fk['columns'] ?? []) === [$column]
                && $this->normalize($fk['foreign_table'] ?? '') === $target
                && ($fk['foreign_columns'] ?? []) === [$parent['column']],
        )) {
            $errors[] = "{$table}.{$column}: declared foreign key to {$target}.{$parent['column']} does not exist.";
        }
    }

    private function normalize(string $table): string
    {
        $parts = explode('.', $table);

        return trim((string) end($parts), '"');
    }

    private function validateParentChains(array $tables, array &$errors): void
    {
        $edges = [];
        foreach ($tables as $table => $entry) {
            if (! is_array($entry) || ! is_array($entry['parents'] ?? null)) {
                continue;
            }
            foreach ($entry['parents'] as $parent) {
                if (! is_array($parent) || ! $this->identifier($parent['table'] ?? null)
                    || ($parent['kind'] ?? null) === 'historical') {
                    continue;
                }
                $target = $parent['table'];
                if ($target === $table && ($parent['source_null_rule'] ?? null) === 'root') {
                    continue;
                }
                $edges[$table][] = $target;
            }
        }
        $visited = [];
        $active = [];
        $visit = function (string $table) use (&$visit, &$visited, &$active, $edges, &$errors): void {
            if (isset($active[$table])) {
                $errors[] = "Cyclic parent chain at {$table}; only explicit root self-hierarchies and historical references are exempt.";

                return;
            }
            if (isset($visited[$table])) {
                return;
            }
            $active[$table] = true;
            foreach ($edges[$table] ?? [] as $parent) {
                $visit($parent);
            }
            unset($active[$table]);
            $visited[$table] = true;
        };
        foreach (array_keys($edges) as $table) {
            $visit($table);
        }
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]*$/D', $value) === 1;
    }

    private function reference(mixed $value): bool
    {
        return $this->text($value) && ! str_contains($value, '..') && ! str_starts_with($value, '/') && is_file(base_path($value));
    }
}
