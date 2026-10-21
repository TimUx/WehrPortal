<?php

declare(strict_types=1);

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\LocationDataInventory;
use Illuminate\Console\Command;

class AuditLocationDataCommand extends Command
{
    protected $signature = 'locations:audit-data {--json : Maschinenlesbare Ausgabe ohne personenbezogene Inhalte}';

    protected $description = 'Zählt Standortzuordnungen ausschließlich lesend; ordnet keine Bestandsdaten automatisch zu';

    public function handle(LocationDataInventory $inventory): int
    {
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
}
