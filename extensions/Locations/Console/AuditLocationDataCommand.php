<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\LocationDataInventory;
use Extensions\Locations\Services\LocationRelationInventory;
use Illuminate\Console\Command;

class AuditLocationDataCommand extends Command
{
    protected $signature = 'locations:audit-data {--json : Maschinenlesbare Ausgabe ohne personenbezogene Inhalte} {--relations : Endliche Manifestbeziehungen im konsistenten Nur-Lese-Snapshot prüfen}';

    protected $description = 'Zählt Standortzuordnungen ausschließlich lesend; ordnet keine Bestandsdaten automatisch zu';

    public function handle(LocationDataInventory $inventory): int
    {
        if ($this->option('relations')) {
            return $this->relations();
        }
        try {
            $rows = $inventory->rows();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'read_only' => true,
                'scope' => 'direct_location_ownership_counts_only',
                'enforcement_verified' => false,
                'tables' => $rows,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->warn('Nur Inventur: keine Zuordnung, Freigabe oder Isolationsbestätigung. Elternbeziehungen und gemeinsame Daten bleiben gesondert zu prüfen.');
            $this->table(['Tabelle', 'Datenklasse', 'Zeilen', 'Standortspalte', 'Ohne Standort', 'Prüfbedarf'],
                array_map(fn (array $row) => array_values([
                    ...$row,
                    'location_column' => $row['location_column'] ?? '—',
                    'unassigned' => $row['unassigned'] ?? '—',
                ]), $rows));
        }

        return self::SUCCESS;
    }

    private function relations(): int
    {
        try {
            $report = app(LocationRelationInventory::class)->report();
        } catch (\RuntimeException) {
            if ($this->option('json')) {
                $this->line(json_encode(['read_only' => true, 'enforcement_verified' => false, 'error' => 'location_relations_unavailable'], JSON_THROW_ON_ERROR));
            } else {
                $this->error(LocationRelationInventory::FAILURE);
            }

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->warn('Nur lesend: Prüfkandidaten, keine bestätigten Verstöße oder Isolation. Summen über Beziehungen sind keine eindeutigen Betroffenenzahlen.');
            $this->table(['Tabelle', 'Kategorie', 'Spalte', 'Anzahl'], $this->technicalRows($report));
            $this->warn('Nicht verifiziert: indirekte, polymorphe und Mehr-Eltern-Eigentümerschaft; keine automatische Zuordnung.');
        }

        return self::SUCCESS;
    }

    private function technicalRows(array $report): array
    {
        $rows = [];
        foreach ($report['tables'] as $table) {
            $rows[] = [$table['table'], $table['availability'], $table['location_column'] ?? '—', $table['rows'] ?? '—'];
            foreach ($table['counts'] ?? [] as $category => $count) {
                $rows[] = [$table['table'], $category, $table['location_column'], $count ?? '—'];
            }
        }
        foreach ($report['relations'] as $relation) {
            $column = $relation['child_column'].' → '.$relation['parent_table'].'.'.$relation['parent_column'];
            $rows[] = [$relation['table'], $relation['kind'].':'.$relation['availability'].':'.$relation['ownership_comparison'], $column, '—'];
            foreach ($relation['counts'] ?? [] as $category => $count) {
                $rows[] = [$relation['table'], $category.':'.$relation['source_null_rule'], $column, $count ?? '—'];
            }
        }
        foreach ($report['limitations'] as $limitation) {
            $rows[] = [$limitation['table'], $limitation['category'], $limitation['id_column'] ?? '—', '—'];
        }

        return $rows;
    }
}
