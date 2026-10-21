<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional module upgrades may not have installed the table or Locations column yet.
        if (! Schema::hasTable('kk_items') || ! Schema::hasColumn('kk_items', 'ext_location_id') || ! Schema::hasTable('x_locations')) {
            return;
        }
        DB::statement('LOCK TABLE kk_items IN ACCESS EXCLUSIVE MODE');
        // Discover by column, not Laravel's conventional constraint name. A custom or
        // absent FK must not leave SET NULL/CASCADE capable of erasing physical ownership.
        foreach (Schema::getForeignKeys('kk_items') as $foreign) {
            if (in_array('ext_location_id', $foreign['columns'], true)) {
                DB::statement('ALTER TABLE kk_items DROP CONSTRAINT "'.str_replace('"', '""', $foreign['name']).'"');
            }
        }
        DB::statement('ALTER TABLE kk_items ADD CONSTRAINT kk_items_ext_location_id_foreign FOREIGN KEY (ext_location_id) REFERENCES x_locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    public function down(): void
    {
        // Preserve restrictive ownership protection, including on upgrades with custom FKs.
        // A rollback cannot safely reconstruct an installation's previous constraint.
    }
};
