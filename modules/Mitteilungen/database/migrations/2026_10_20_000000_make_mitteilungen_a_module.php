<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('modules')->upsert(
            [['key' => 'mitteilungen', 'is_active' => true, 'auto_assign' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['updated_at'],
        );

    }

    public function down(): void
    {
        DB::table('module_user')->where('module', 'mitteilungen')->delete();
        DB::table('modules')->where('key', 'mitteilungen')->delete();
    }
};
