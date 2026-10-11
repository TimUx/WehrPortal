<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Anna Admin',
                'email' => 'admin@example.com',
                'is_admin' => true,
                'is_active' => true,
            ]
        );
    }
}
