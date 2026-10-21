<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_location_module_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('x_locations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('module', 100);
            $table->string('role', 100)->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at');
            $table->string('reason', 500);
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX x_location_module_roles_open ON x_location_module_roles (location_id, user_id, module) WHERE revoked_at IS NULL');
        DB::statement('ALTER TABLE x_location_module_roles ADD CONSTRAINT x_location_module_roles_dates CHECK ((expires_at IS NULL OR expires_at > granted_at) AND (revoked_at IS NULL OR revoked_at >= granted_at))');
        DB::statement('ALTER TABLE x_location_module_roles ADD CONSTRAINT x_location_module_roles_revocation CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND length(trim(revoke_reason)) BETWEEN 1 AND 500))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_location_module_roles_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Location module role history cannot be deleted';
                END IF;
                IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
                    OR (to_jsonb(NEW) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['revoked_at', 'revoked_by', 'revoke_reason']) THEN
                    RAISE EXCEPTION 'Only a one-way revocation may close a location module grant';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_location_module_roles_history BEFORE UPDATE OR DELETE ON x_location_module_roles
                FOR EACH ROW EXECUTE FUNCTION x_location_module_roles_history();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('x_location_module_roles');
        DB::statement('DROP FUNCTION IF EXISTS x_location_module_roles_history()');
    }
};
