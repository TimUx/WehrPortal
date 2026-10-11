<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Kleiderkammer\database\seeders\KleiderkammerDemoDataSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoDataSeeder::class,
            UserSeeder::class,
            KleiderkammerDemoDataSeeder::class,
        ]);
    }
}
