<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_leadership_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('x_locations')->restrictOnDelete();
            $table->string('profile', 100);
            $table->jsonb('capabilities');
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('reason', 500);
            $table->timestampTz('granted_at');
            $table->timestampTz('valid_from');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoke_reason', 500)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX x_leadership_assignments_open ON x_leadership_assignments (user_id, location_id, profile) WHERE revoked_at IS NULL');
        DB::statement("ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_dates CHECK (valid_from >= granted_at AND expires_at > valid_from AND expires_at <= granted_at + interval '366 days' AND (revoked_at IS NULL OR revoked_at >= granted_at))");
        DB::statement('ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_reason CHECK (length(trim(reason)) BETWEEN 1 AND 500)');
        DB::statement("ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_approval CHECK (
            (profile = 'kommunaler_admin' AND granted_by <> user_id AND
                ((approved_by IS NULL AND approved_at IS NULL) OR
                 (approved_by IS NOT NULL AND approved_at IS NOT NULL AND approved_by <> granted_by AND approved_by <> user_id AND approved_at >= granted_at AND approved_at < expires_at)))
            OR (profile <> 'kommunaler_admin' AND approved_by IS NULL AND approved_at IS NULL))");
        DB::statement('ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_revocation CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoke_reason IS NOT NULL AND length(trim(revoke_reason)) BETWEEN 1 AND 500))');
        DB::statement(<<<'SQL'
            ALTER TABLE x_leadership_assignments ADD CONSTRAINT x_leadership_capabilities CHECK (
                jsonb_typeof(capabilities) = 'array' AND jsonb_array_length(capabilities) > 0 AND
                CASE profile
                    WHEN 'buergermeister' THEN capabilities <@ '["incident_totals"]'::jsonb
                    WHEN 'kommune' THEN capabilities <@ '["incident_totals"]'::jsonb
                    WHEN 'brandschutzamt' THEN capabilities <@ '["fleet_deadlines","equipment_deadlines"]'::jsonb
                    WHEN 'gbi' THEN capabilities <@ '["fleet_summary","stock_summary"]'::jsonb
                    WHEN 'spi' THEN capabilities <@ '["fleet_summary","stock_summary"]'::jsonb
                    WHEN 'kommunaler_admin' THEN capabilities <@ '["announcement_configuration"]'::jsonb
                    ELSE false
                END
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_leadership_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Leadership assignments must begin without approval';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Leadership assignment history cannot be deleted';
                END IF;
                IF OLD.revoked_at IS NULL AND OLD.profile = 'kommunaler_admin'
                    AND OLD.approved_by IS NULL AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL
                    AND (to_jsonb(NEW) - ARRAY['approved_at', 'approved_by'])
                        IS NOT DISTINCT FROM (to_jsonb(OLD) - ARRAY['approved_at', 'approved_by']) THEN
                    RETURN NEW;
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason']) THEN
                    RAISE EXCEPTION 'Only one-way separate approval or revocation may change leadership history';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_leadership_history BEFORE INSERT OR UPDATE OR DELETE ON x_leadership_assignments
                FOR EACH ROW EXECUTE FUNCTION x_leadership_history();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('x_leadership_assignments');
        DB::statement('DROP FUNCTION IF EXISTS x_leadership_history()');
    }
};
