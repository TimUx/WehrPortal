<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kk_issued_items') || ! Schema::hasTable('kk_stock')) {
            return;
        }
        DB::statement('LOCK TABLE kk_issued_items, kk_stock IN ACCESS EXCLUSIVE MODE');
        if (Schema::hasColumn('kk_issued_items', 'ext_source_stock_id')) {
            throw new RuntimeException('Stock source column already exists; refusing to replace custom provenance or constraints.');
        }
        Schema::table('kk_issued_items', fn (Blueprint $table) => $table->foreignId('ext_source_stock_id')->nullable()->index());
        DB::statement('ALTER TABLE kk_issued_items ADD CONSTRAINT kk_issued_items_ext_source_stock_id_foreign FOREIGN KEY (ext_source_stock_id) REFERENCES kk_stock(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::statement('ALTER TABLE kk_issued_items ADD CONSTRAINT kk_issued_items_stock_source_bulk CHECK (ext_source_stock_id IS NULL OR (item_id IS NULL AND quantity > 0))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_locations_clothing_stock_source_guard() RETURNS trigger AS $$
            DECLARE source kk_stock%ROWTYPE;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.ext_source_stock_id IS DISTINCT FROM OLD.ext_source_stock_id THEN
                        RAISE EXCEPTION 'Issuance stock source is immutable' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.ext_source_stock_id IS NOT NULL THEN
                    SELECT * INTO source FROM kk_stock WHERE id = NEW.ext_source_stock_id FOR SHARE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Issuance stock source does not exist' USING ERRCODE = '23503';
                    END IF;
                    IF NOT (NEW.item_type_id IS NOT DISTINCT FROM source.item_type_id
                        AND NEW.size_id IS NOT DISTINCT FROM source.size_id
                        AND NEW.group_id IS NOT DISTINCT FROM source.group_id) THEN
                        RAISE EXCEPTION 'Issuance catalog must match its stock source at insertion' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_locations_clothing_stock_source_guard
                BEFORE INSERT OR UPDATE ON kk_issued_items
                FOR EACH ROW EXECUTE FUNCTION x_locations_clothing_stock_source_guard();
            SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('kk_issued_items') && Schema::hasColumn('kk_issued_items', 'ext_source_stock_id')) {
            DB::statement('LOCK TABLE kk_issued_items IN ACCESS EXCLUSIVE MODE');
            if (DB::table('kk_issued_items')->whereNotNull('ext_source_stock_id')->exists()) {
                throw new RuntimeException('Issuance stock provenance exists; refusing to remove historical sources.');
            }
            DB::statement('DROP TRIGGER IF EXISTS x_locations_clothing_stock_source_guard ON kk_issued_items');
            Schema::table('kk_issued_items', fn (Blueprint $table) => $table->dropColumn('ext_source_stock_id'));
        }
        DB::statement('DROP FUNCTION IF EXISTS x_locations_clothing_stock_source_guard()');
    }
};
