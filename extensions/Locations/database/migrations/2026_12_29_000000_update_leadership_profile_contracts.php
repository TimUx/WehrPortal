<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE x_leadership_assignments DROP CONSTRAINT x_leadership_capabilities');
        DB::statement(<<<'SQL'
            ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_capabilities CHECK (
                jsonb_typeof(capabilities) = 'array' AND jsonb_array_length(capabilities) > 0 AND
                CASE profile
                    WHEN 'buergermeister' THEN capabilities <@ '["incident_totals"]'::jsonb
                    WHEN 'kommune' THEN capabilities <@ '["incident_totals"]'::jsonb
                    WHEN 'brandschutzamt' THEN capabilities <@ '["fleet_deadlines","equipment_deadlines","fleet_inspections","equipment_inspections","stock_summary","personnel_summary"]'::jsonb
                    WHEN 'gbi' THEN capabilities <@ '["location_overview","fleet_summary","equipment_summary","stock_summary","personnel_summary"]'::jsonb
                    WHEN 'spi' THEN capabilities <@ '["location_overview","fleet_summary","equipment_summary","stock_summary","personnel_summary"]'::jsonb
                    WHEN 'kommunaler_admin' THEN capabilities <@ '["announcement_configuration"]'::jsonb
                    ELSE false
                END
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_leadership_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.profile = 'kommunaler_admin' THEN
                        RAISE EXCEPTION 'Municipal administration is not supported';
                    END IF;
                    IF NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Leadership assignments must begin without approval';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Leadership assignment history cannot be deleted';
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason']) THEN
                    RAISE EXCEPTION 'Only one-way revocation may change leadership history';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        // Expanded immutable grants cannot be safely narrowed on rollback.
    }
};
