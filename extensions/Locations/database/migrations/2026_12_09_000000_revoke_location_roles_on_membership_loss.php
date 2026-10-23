<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_location_module_roles', fn (Blueprint $table) => $table->string('revoked_source', 20)->nullable());

        DB::statement('DROP TRIGGER x_location_module_roles_history ON x_location_module_roles');
        DB::table('x_location_module_roles')->whereNotNull('revoked_at')->update(['revoked_source' => 'admin']);
        DB::statement('ALTER TABLE x_location_module_roles DROP CONSTRAINT x_location_module_roles_revocation');
        DB::statement(<<<'SQL'
            ALTER TABLE x_location_module_roles ADD CONSTRAINT x_location_module_roles_revocation CHECK (
                (revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL AND revoked_source IS NULL)
                OR (revoked_at IS NOT NULL AND revoke_reason IS NOT NULL AND revoked_source IS NOT NULL
                    AND length(trim(revoke_reason)) BETWEEN 1 AND 500
                    AND ((revoked_source = 'admin' AND revoked_by IS NOT NULL)
                        OR (revoked_source = 'membership' AND revoked_by IS NULL))))
            SQL);
        $this->historyTrigger(true);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_location_membership_loss() RETURNS trigger AS $$
            DECLARE
                target_user_id bigint;
                target_location_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'users' THEN
                    target_user_id := OLD.id;
                    target_location_id := OLD.ext_location_id;
                ELSE
                    target_user_id := OLD.user_id;
                    target_location_id := OLD.location_id;
                END IF;
                IF target_location_id IS NULL THEN
                    RETURN NULL;
                END IF;

                -- Serialize pivot removal with primary-location changes and grant issuance.
                PERFORM 1 FROM users WHERE id = target_user_id FOR UPDATE;
                IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = target_user_id AND u.ext_location_id = target_location_id)
                    AND NOT EXISTS (SELECT 1 FROM x_location_user m WHERE m.user_id = target_user_id AND m.location_id = target_location_id) THEN
                    UPDATE x_location_module_roles g
                    SET revoked_at = GREATEST(g.granted_at, clock_timestamp()),
                        revoked_by = NULL, revoked_source = 'membership',
                        revoke_reason = 'Automatischer Entzug: effektive Standortmitgliedschaft beendet.'
                    WHERE g.user_id = target_user_id AND g.location_id = target_location_id AND g.revoked_at IS NULL;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER x_location_user_membership_loss
                AFTER DELETE OR UPDATE OF location_id, user_id ON x_location_user
                FOR EACH ROW EXECUTE FUNCTION x_location_membership_loss();
            CREATE TRIGGER x_location_primary_membership_loss
                AFTER UPDATE OF ext_location_id ON users
                FOR EACH ROW EXECUTE FUNCTION x_location_membership_loss();
            SQL);

        DB::statement(<<<'SQL'
            UPDATE x_location_module_roles g
            SET revoked_at = GREATEST(g.granted_at, clock_timestamp()),
                revoked_by = NULL, revoked_source = 'membership',
                revoke_reason = 'Automatischer Entzug: keine effektive Standortmitgliedschaft bei Einführung des Schutzes.'
            WHERE g.revoked_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = g.user_id AND u.ext_location_id = g.location_id)
                AND NOT EXISTS (SELECT 1 FROM x_location_user m WHERE m.user_id = g.user_id AND m.location_id = g.location_id)
            SQL);
    }

    public function down(): void
    {
        if (DB::table('x_location_module_roles')->where('revoked_source', 'membership')->exists()) {
            throw new RuntimeException('Membership revocation history cannot safely be downgraded to admin-only history.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS x_location_user_membership_loss ON x_location_user;
            DROP TRIGGER IF EXISTS x_location_primary_membership_loss ON users;
            DROP FUNCTION IF EXISTS x_location_membership_loss();
            DROP TRIGGER x_location_module_roles_history ON x_location_module_roles;
            ALTER TABLE x_location_module_roles DROP CONSTRAINT x_location_module_roles_revocation;
            SQL);
        Schema::table('x_location_module_roles', fn (Blueprint $table) => $table->dropColumn('revoked_source'));
        DB::statement('ALTER TABLE x_location_module_roles ADD CONSTRAINT x_location_module_roles_revocation CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND length(trim(revoke_reason)) BETWEEN 1 AND 500))');
        $this->historyTrigger(false);
    }

    private function historyTrigger(bool $withSource): void
    {
        $fields = "'revoked_at', 'revoked_by', 'revoke_reason'".($withSource ? ", 'revoked_source'" : '');
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION x_location_module_roles_history() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Location module role history cannot be deleted';
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY[{$fields}])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY[{$fields}]) THEN
                    RAISE EXCEPTION 'Only a one-way revocation may close a location module grant';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER x_location_module_roles_history BEFORE UPDATE OR DELETE ON x_location_module_roles
                FOR EACH ROW EXECUTE FUNCTION x_location_module_roles_history();
            SQL);
    }
};
