<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target');
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_target CHECK (target_table IN ('x_att_lists', 'x_att_templates', 'kk_items') AND target_id > 0)");
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE x_location_data_assignments IN ACCESS EXCLUSIVE MODE');
        if (DB::table('x_location_data_assignments')->where('target_table', 'kk_items')->exists()) {
            throw new RuntimeException('Clothing assignment history exists; refusing to narrow the journal constraint.');
        }
        DB::statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target');
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_target CHECK (target_table IN ('x_att_lists', 'x_att_templates') AND target_id > 0)");
    }
};
