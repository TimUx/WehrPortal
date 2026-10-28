<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_standard_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('profile', 100);
            $table->foreignId('location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at');
            $table->string('reason', 500);
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->string('revoked_source', 20)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX x_standard_role_assignments_open_global ON x_standard_role_assignments (user_id, profile) WHERE revoked_at IS NULL AND location_id IS NULL AND group_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX x_standard_role_assignments_open_global_group ON x_standard_role_assignments (user_id, profile, group_id) WHERE revoked_at IS NULL AND location_id IS NULL AND group_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX x_standard_role_assignments_open_location ON x_standard_role_assignments (user_id, profile, location_id) WHERE revoked_at IS NULL AND location_id IS NOT NULL AND group_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX x_standard_role_assignments_open_location_group ON x_standard_role_assignments (user_id, profile, location_id, group_id) WHERE revoked_at IS NULL AND location_id IS NOT NULL AND group_id IS NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE x_standard_role_assignments ADD CONSTRAINT x_standard_role_scope CHECK (
                (profile IN ('buergermeister', 'brandschutzamt', 'gemeindebrandinspektor', 'atemschutzwart')
                    AND location_id IS NULL AND group_id IS NULL)
                OR (profile IN ('gemeindejugendwart', 'gemeindekinderwart')
                    AND location_id IS NULL)
                OR (profile IN ('wehrfuehrung', 'geraetewart')
                    AND location_id IS NOT NULL AND group_id IS NULL)
                OR (profile IN ('jugendwart', 'kinderwart')
                    AND location_id IS NOT NULL AND group_id IS NOT NULL)
            )
            SQL);
        DB::statement("ALTER TABLE x_standard_role_assignments ADD CONSTRAINT x_standard_role_profile CHECK (profile IN ('buergermeister', 'brandschutzamt', 'gemeindebrandinspektor', 'gemeindejugendwart', 'gemeindekinderwart', 'atemschutzwart', 'wehrfuehrung', 'jugendwart', 'kinderwart', 'geraetewart'))");
        DB::statement('ALTER TABLE x_standard_role_assignments ADD CONSTRAINT x_standard_role_dates CHECK ((expires_at IS NULL OR expires_at > granted_at) AND (revoked_at IS NULL OR revoked_at >= granted_at))');
        DB::statement("ALTER TABLE x_standard_role_assignments ADD CONSTRAINT x_standard_role_revocation CHECK (
            (revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL AND revoked_source IS NULL)
            OR (revoked_at IS NOT NULL AND revoked_source = 'admin' AND revoked_by IS NOT NULL AND revoke_reason IS NOT NULL AND length(trim(revoke_reason)) BETWEEN 1 AND 500)
            OR (revoked_at IS NOT NULL AND revoked_source = 'membership' AND revoked_by IS NULL AND revoke_reason = 'Standortmitgliedschaft beendet')
        )");
        DB::statement('ALTER TABLE x_standard_role_assignments ADD CONSTRAINT x_standard_role_reason CHECK (length(trim(reason)) BETWEEN 1 AND 500)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_standard_role_validate_assignment() RETURNS trigger AS $$
            DECLARE
                group_name text;
                group_location bigint;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_active = true) THEN
                    RAISE EXCEPTION 'Standard role assignee must be active';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.granted_by AND is_active = true AND is_admin = true) THEN
                    RAISE EXCEPTION 'Standard role grantor must be an active global admin';
                END IF;
                IF NEW.group_id IS NOT NULL THEN
                    SELECT lower(name), ext_location_id INTO group_name, group_location FROM groups WHERE id = NEW.group_id;
                    IF group_name IS NULL
                        OR (NEW.profile IN ('jugendwart', 'gemeindejugendwart') AND group_name <> 'jugendfeuerwehr')
                        OR (NEW.profile IN ('kinderwart', 'gemeindekinderwart') AND group_name <> 'kinderfeuerwehr')
                        OR (NEW.location_id IS NOT NULL AND NEW.location_id <> group_location)
                        OR NOT EXISTS (SELECT 1 FROM x_locations WHERE id = group_location AND is_active = true) THEN
                        RAISE EXCEPTION 'Standard role group must match its profile and active location';
                    END IF;
                END IF;
                IF NEW.location_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM users WHERE id = NEW.user_id AND ext_location_id = NEW.location_id
                    UNION ALL
                    SELECT 1 FROM x_location_user WHERE user_id = NEW.user_id AND location_id = NEW.location_id
                ) THEN
                    RAISE EXCEPTION 'Standard role assignee must belong to its location';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_standard_role_validate BEFORE INSERT ON x_standard_role_assignments
                FOR EACH ROW EXECUTE FUNCTION x_standard_role_validate_assignment();
            CREATE OR REPLACE FUNCTION x_standard_role_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Standard role assignment history cannot be deleted';
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason', 'revoked_source'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason', 'revoked_source'])
                    OR NEW.revoked_source NOT IN ('admin', 'membership') THEN
                    RAISE EXCEPTION 'Only a one-way revocation may close a standard role assignment';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_standard_role_history BEFORE UPDATE OR DELETE ON x_standard_role_assignments
                FOR EACH ROW EXECUTE FUNCTION x_standard_role_history();
            CREATE OR REPLACE FUNCTION x_standard_role_revoke_membership() RETURNS trigger AS $$
            BEGIN
                UPDATE x_standard_role_assignments AS grants
                SET revoked_at = now(), revoked_by = NULL,
                    revoke_reason = 'Standortmitgliedschaft beendet', revoked_source = 'membership'
                WHERE grants.user_id = OLD.user_id AND grants.location_id = OLD.location_id
                    AND grants.revoked_at IS NULL AND OLD.location_id IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM users
                        WHERE users.id = OLD.user_id AND users.ext_location_id = OLD.location_id
                    );
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_standard_role_membership_loss AFTER DELETE ON x_location_user
                FOR EACH ROW EXECUTE FUNCTION x_standard_role_revoke_membership();
            CREATE OR REPLACE FUNCTION x_standard_role_revoke_primary_location() RETURNS trigger AS $$
            BEGIN
                IF OLD.ext_location_id IS NOT NULL AND OLD.ext_location_id IS DISTINCT FROM NEW.ext_location_id THEN
                    UPDATE x_standard_role_assignments AS grants
                    SET revoked_at = now(), revoked_by = NULL,
                        revoke_reason = 'Standortmitgliedschaft beendet', revoked_source = 'membership'
                    WHERE grants.user_id = NEW.id AND grants.location_id = OLD.ext_location_id
                        AND grants.revoked_at IS NULL
                        AND NOT EXISTS (
                            SELECT 1 FROM x_location_user
                            WHERE x_location_user.user_id = NEW.id AND x_location_user.location_id = OLD.ext_location_id
                        );
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_standard_role_primary_location_loss AFTER UPDATE OF ext_location_id ON users
                FOR EACH ROW EXECUTE FUNCTION x_standard_role_revoke_primary_location();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS x_standard_role_membership_loss ON x_location_user');
        DB::statement('DROP TRIGGER IF EXISTS x_standard_role_primary_location_loss ON users');
        Schema::dropIfExists('x_standard_role_assignments');
        DB::statement('DROP FUNCTION IF EXISTS x_standard_role_validate_assignment()');
        DB::statement('DROP FUNCTION IF EXISTS x_standard_role_history()');
        DB::statement('DROP FUNCTION IF EXISTS x_standard_role_revoke_membership()');
        DB::statement('DROP FUNCTION IF EXISTS x_standard_role_revoke_primary_location()');
    }
};
