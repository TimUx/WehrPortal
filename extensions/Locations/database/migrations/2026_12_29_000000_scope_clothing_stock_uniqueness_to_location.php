<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kk_stock', 'ext_location_id')) {
            return;
        }
        DB::statement('LOCK TABLE kk_stock IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE kk_stock DROP CONSTRAINT kk_stock_item_type_id_size_id_group_id_unique');
        DB::statement('ALTER TABLE kk_stock ADD CONSTRAINT kk_stock_location_catalog_unique UNIQUE NULLS NOT DISTINCT (ext_location_id, item_type_id, size_id, group_id)');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kk_stock', 'ext_location_id')) {
            return;
        }
        DB::statement('LOCK TABLE kk_stock IN ACCESS EXCLUSIVE MODE');
        if (DB::table('kk_stock')->select(['item_type_id', 'size_id', 'group_id'])
            ->groupBy('item_type_id', 'size_id', 'group_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Multiple warehouses share a catalog tuple; refusing to merge stock or discard ownership.');
        }
        DB::statement('ALTER TABLE kk_stock DROP CONSTRAINT kk_stock_location_catalog_unique');
        DB::statement('ALTER TABLE kk_stock ADD CONSTRAINT kk_stock_item_type_id_size_id_group_id_unique UNIQUE NULLS NOT DISTINCT (item_type_id, size_id, group_id)');
    }
};
