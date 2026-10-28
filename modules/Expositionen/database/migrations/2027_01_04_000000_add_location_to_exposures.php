<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ex_exposures', function (Blueprint $table) {
            $table->unsignedBigInteger('ext_location_id')->nullable();
            $table->index('ext_location_id', 'ex_exposures_ext_location_id_index');
            if (Schema::hasTable('x_locations')) {
                $table->foreign('ext_location_id', 'ex_exposures_ext_location_fk')
                    ->references('id')->on('x_locations')->restrictOnDelete();
            }
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ex_exposures_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.ext_location_id IS DISTINCT FROM OLD.ext_location_id THEN
                        RAISE EXCEPTION 'ex_exposures: Standort ownership cannot be changed for exposure %', OLD.id USING ERRCODE = '23001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status <> 'draft' AND OLD.retain_until >= CURRENT_DATE THEN
                    RAISE EXCEPTION 'ex_exposures: exposure % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                END IF;
                RETURN OLD;
            END $$;

            DROP TRIGGER IF EXISTS ex_exposures_protect ON ex_exposures;
            CREATE TRIGGER ex_exposures_protect BEFORE UPDATE OR DELETE ON ex_exposures
                FOR EACH ROW EXECUTE FUNCTION ex_exposures_protect();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ex_exposures_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'draft' AND OLD.retain_until >= CURRENT_DATE THEN
                    RAISE EXCEPTION 'ex_exposures: exposure % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                END IF;
                RETURN OLD;
            END $$;

            DROP TRIGGER IF EXISTS ex_exposures_protect ON ex_exposures;
            CREATE TRIGGER ex_exposures_protect BEFORE DELETE ON ex_exposures
                FOR EACH ROW EXECUTE FUNCTION ex_exposures_protect();
            SQL);

        Schema::table('ex_exposures', function (Blueprint $table) {
            if (Schema::hasTable('x_locations')) {
                $table->dropForeign('ex_exposures_ext_location_fk');
            }
            $table->dropIndex('ex_exposures_ext_location_id_index');
            $table->dropColumn('ext_location_id');
        });
    }
};
