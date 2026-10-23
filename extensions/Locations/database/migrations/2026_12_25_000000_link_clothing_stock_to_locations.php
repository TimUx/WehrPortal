<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kk_stock') || ! Schema::hasTable('x_locations')) {
            return;
        }
        DB::statement('LOCK TABLE kk_stock IN ACCESS EXCLUSIVE MODE');
        if (! Schema::hasColumn('kk_stock', 'ext_location_id')) {
            Schema::table('kk_stock', fn (Blueprint $table) => $table->foreignId('ext_location_id')->nullable());
        }
        // Preserve the global type/size/group unique key: issue and return still
        // select globally and cannot distinguish stock rows at different sites.
        foreach (Schema::getForeignKeys('kk_stock') as $foreign) {
            if (in_array('ext_location_id', $foreign['columns'], true)) {
                DB::statement('ALTER TABLE kk_stock DROP CONSTRAINT "'.str_replace('"', '""', $foreign['name']).'"');
            }
        }
        DB::statement('ALTER TABLE kk_stock ADD CONSTRAINT kk_stock_ext_location_id_foreign FOREIGN KEY (ext_location_id) REFERENCES x_locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (Schema::hasTable('x_location_data_assignments')) {
            DB::statement('LOCK TABLE x_location_data_assignments IN ACCESS EXCLUSIVE MODE');
            if (DB::table('x_location_data_assignments')->where('target_table', 'kk_stock')->exists()) {
                throw new RuntimeException('Stock assignment history exists; refusing to remove stock ownership.');
            }
        }
        if (! Schema::hasTable('kk_stock') || ! Schema::hasColumn('kk_stock', 'ext_location_id')) {
            return;
        }
        DB::statement('LOCK TABLE kk_stock IN ACCESS EXCLUSIVE MODE');
        if (DB::table('kk_stock')->whereNotNull('ext_location_id')->exists()) {
            throw new RuntimeException('Owned stock exists; refusing to remove stock ownership.');
        }
        // Dropping the column also removes custom foreign keys on that column.
        Schema::table('kk_stock', fn (Blueprint $table) => $table->dropColumn('ext_location_id'));
    }
};
