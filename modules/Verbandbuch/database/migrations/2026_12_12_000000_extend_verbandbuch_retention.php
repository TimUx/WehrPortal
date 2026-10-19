<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Verbandbuch entries are kept 10 years instead of 5. The protection trigger now lets
     * retain_until grow (never shrink), and the entries kept so far get the 5 more years.
     */
    public function up(): void
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

        // Kept so far: end of the year of signing + 5; now + 10
        DB::statement("UPDATE vb_entries SET retain_until = make_date(EXTRACT(YEAR FROM created_at AT TIME ZONE 'Europe/Berlin')::int + 10, 12, 31)
            WHERE retain_until < make_date(EXTRACT(YEAR FROM created_at AT TIME ZONE 'Europe/Berlin')::int + 10, 12, 31)");
    }

    public function down(): void
    {
        // The longer retention stays: shortening it would allow deleting entries that must be kept
    }
};
