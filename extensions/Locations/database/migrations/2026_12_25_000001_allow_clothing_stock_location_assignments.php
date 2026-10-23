<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('x_location_data_assignments')) {
            return;
        }
        DB::statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target');
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_target CHECK (target_table IN ('x_att_lists', 'x_att_templates', 'kk_items', 'kk_stock') AND target_id > 0)");
    }

    public function down(): void
    {
        if (! Schema::hasTable('x_location_data_assignments')) {
            return;
        }
        DB::statement('LOCK TABLE x_location_data_assignments IN ACCESS EXCLUSIVE MODE');
        if (DB::table('x_location_data_assignments')->where('target_table', 'kk_stock')->exists()) {
            throw new RuntimeException('Stock assignment history exists; refusing to narrow the journal constraint.');
        }
        DB::statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target');
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_target CHECK (target_table IN ('x_att_lists', 'x_att_templates', 'kk_items') AND target_id > 0)");
    }
};
