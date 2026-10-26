<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultLocationId = DB::table('x_locations')->where('is_default', true)->value('id')
            ?? DB::table('x_locations')->orderBy('sort_order')->orderBy('id')->value('id');

        if ($defaultLocationId === null) {
            throw new RuntimeException('A default Standort is required before assigning members.');
        }

        DB::table('users')->whereNull('ext_location_id')->update(['ext_location_id' => $defaultLocationId]);
        DB::statement(<<<'SQL'
            INSERT INTO x_location_user (location_id, user_id, role)
            SELECT ext_location_id, id, 'member'
            FROM users
            WHERE ext_location_id IS NOT NULL
            ON CONFLICT (location_id, user_id) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // Location membership edits are operational data and must not be undone by a rollback.
    }
};
