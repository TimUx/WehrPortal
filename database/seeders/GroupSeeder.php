<?php

namespace Database\Seeders;

use App\Models\Group;
use Illuminate\Database\Seeder;

class GroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['name' => 'Aktiver Dienst', 'sort_order' => 1],
            ['name' => 'Jugendfeuerwehr', 'sort_order' => 2],
            ['name' => 'Musikzug', 'sort_order' => 3],
            ['name' => 'Wehrführer', 'sort_order' => 4],
        ];

        foreach ($groups as $group) {
            Group::firstOrCreate(['name' => $group['name']], $group);
        }
    }
}
