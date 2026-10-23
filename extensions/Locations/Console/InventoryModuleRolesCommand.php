<?php

namespace Extensions\Locations\Console;

use Extensions\Locations\Services\LegacyModuleRoleInventory;
use Illuminate\Console\Command;

class InventoryModuleRolesCommand extends Command
{
    protected $signature = 'locations:inventory-module-roles';

    protected $description = 'Nur lesende Bestandsaufnahme alter Modulrollen; keine automatische Zuweisung';

    public function handle(LegacyModuleRoleInventory $inventory): int
    {
        $this->warn('Nur Kandidaten, keine Berechtigung und keine Migration. Globale Admins müssen ausdrücklich je Standort zuweisen.');
        $this->table(['Mitglied-ID', 'Modul', 'Alte Rolle', 'Kandidaten', 'Standort-IDs'],
            array_map(fn (array $row) => [$row['user_id'], $row['module'], $row['role'] ?? 'Zugang',
                $row['classification'], implode(', ', $row['candidate_location_ids'])], $inventory->rows()));

        return self::SUCCESS;
    }
}
