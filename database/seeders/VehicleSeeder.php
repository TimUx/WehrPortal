<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            [
                'name' => 'MTW alt',
                'short_name' => 'MTW-A',
                'color' => '#ef4444',
                'description' => 'Mannschaftstransportwagen (alt)',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'MTW neu',
                'short_name' => 'MTW-N',
                'color' => '#3b82f6',
                'description' => 'Mannschaftstransportwagen (neu)',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'JF-Anhänger',
                'short_name' => 'JF-Anh',
                'type' => 'trailer',
                'color' => '#22c55e',
                'description' => 'Anhänger Jugendfeuerwehr',
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::firstOrCreate(['name' => $vehicle['name']], $vehicle);
        }
    }
}
