<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vb_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('ext_location_id')->nullable();
            $table->index('ext_location_id', 'vb_entries_ext_location_id_index');
            if (Schema::hasTable('x_locations')) {
                $table->foreign('ext_location_id', 'vb_entries_ext_location_fk')
                    ->references('id')->on('x_locations')->restrictOnDelete();
            }
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vb_entries_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.hold OR OLD.retain_until >= CURRENT_DATE THEN
                        RAISE EXCEPTION 'vb_entries: entry % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.retain_until < OLD.retain_until THEN
                    RAISE EXCEPTION 'vb_entries: the retention of entry % cannot be shortened', OLD.id USING ERRCODE = '23001';
                END IF;
                IF (NEW.year, NEW.number, NEW.kind, NEW.occasion, NEW.occurred_at, NEW.location, NEW.ext_location_id, NEW.injured_name,
                    NEW.description, NEW.injury, NEW.witnesses, NEW.first_aid_at, NEW.first_aid_measures, NEW.first_aider_name,
                    NEW.doctor_visited, NEW.recorded_by_name, NEW.content_hash, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.year, OLD.number, OLD.kind, OLD.occasion, OLD.occurred_at, OLD.location, OLD.ext_location_id, OLD.injured_name,
                    OLD.description, OLD.injury, OLD.witnesses, OLD.first_aid_at, OLD.first_aid_measures, OLD.first_aider_name,
                    OLD.doctor_visited, OLD.recorded_by_name, OLD.content_hash, OLD.created_at) THEN
                    RAISE EXCEPTION 'vb_entries: entry % is signed and cannot be changed', OLD.id USING ERRCODE = '23001';
                END IF;
                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vb_entries_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.hold OR OLD.retain_until >= CURRENT_DATE THEN
                        RAISE EXCEPTION 'vb_entries: entry % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.retain_until < OLD.retain_until THEN
                    RAISE EXCEPTION 'vb_entries: the retention of entry % cannot be shortened', OLD.id USING ERRCODE = '23001';
                END IF;
                IF (NEW.year, NEW.number, NEW.kind, NEW.occasion, NEW.occurred_at, NEW.location, NEW.injured_name, NEW.description,
                    NEW.injury, NEW.witnesses, NEW.first_aid_at, NEW.first_aid_measures, NEW.first_aider_name, NEW.doctor_visited,
                    NEW.recorded_by_name, NEW.content_hash, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.year, OLD.number, OLD.kind, OLD.occasion, OLD.occurred_at, OLD.location, OLD.injured_name, OLD.description,
                    OLD.injury, OLD.witnesses, OLD.first_aid_at, OLD.first_aid_measures, OLD.first_aider_name, OLD.doctor_visited,
                    OLD.recorded_by_name, OLD.content_hash, OLD.created_at) THEN
                    RAISE EXCEPTION 'vb_entries: entry % is signed and cannot be changed', OLD.id USING ERRCODE = '23001';
                END IF;
                RETURN NEW;
            END $$;
            SQL);

        Schema::table('vb_entries', function (Blueprint $table) {
            if (Schema::hasTable('x_locations')) {
                $table->dropForeign('vb_entries_ext_location_fk');
            }
            $table->dropIndex('vb_entries_ext_location_id_index');
            $table->dropColumn('ext_location_id');
        });
    }
};
