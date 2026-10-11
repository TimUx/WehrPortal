<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->upsert(
            [['key' => 'fahrzeugbuchung', 'is_active' => true, 'auto_assign' => true, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['is_active', 'auto_assign', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'fahrzeugbuchung')->delete();
    }
};
