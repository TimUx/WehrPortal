<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use Illuminate\Support\Facades\DB;

class LocationDataInventory
{
    /**
     * Counts only: never export member data or guess a Standort for existing rows.
     *
     * @return list<array{table: string, data_class: string, rows: int, location_column: ?string, unassigned: ?int, review: string}>
     */
    public function rows(): array
    {
        $guard = app(ProtectionManifest::class);
        $manifest = config('locations.protection');
        $snapshot = $guard->schema();
        $violations = $guard->violations($manifest, $snapshot);
        if ($violations !== []) {
            throw new \RuntimeException('Schutzmanifest ungültig: '.implode('; ', $violations));
        }

        $schema = [];
        foreach ($snapshot as $table => $description) {
            $schema[str($table)->afterLast('.')->toString()] = $description;
        }

        $rows = [];
        foreach ($manifest['tables'] as $table => $definition) {
            if (! isset($schema[$table])) {
                continue;
            }

            $locationColumn = null;
            foreach ($definition['parents'] as $parent) {
                if ($parent['table'] === 'x_locations' && in_array($parent['child_column'], $schema[$table]['columns'], true)) {
                    $locationColumn = $parent['child_column'];
                    break;
                }
            }

            $query = DB::table($table);
            $unassigned = $locationColumn ? (clone $query)->whereNull($locationColumn)->count() : null;
            $rows[] = [
                'table' => $table,
                'data_class' => $definition['data_class'],
                'rows' => $query->count(),
                'location_column' => $locationColumn,
                'unassigned' => $unassigned,
                'review' => $locationColumn
                    ? ($unassigned > 0 ? 'null_ownership_review' : 'direct_ownership_present')
                    : 'parent_or_global_policy_review',
            ];
        }

        return $rows;
    }
}
