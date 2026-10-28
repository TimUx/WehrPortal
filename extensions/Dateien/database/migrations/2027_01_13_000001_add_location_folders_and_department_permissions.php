<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_files_spaces', function (Blueprint $table) {
            $table->boolean('is_location_default')->default(false);
        });
        Schema::table('x_files_permissions', function (Blueprint $table) {
            $table->foreignId('subject_department_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE x_files_permissions DROP CONSTRAINT x_files_permission_valid');
        DB::statement(<<<'SQL'
            ALTER TABLE x_files_permissions ADD CONSTRAINT x_files_permission_valid CHECK (
                subject_type IN ('user', 'group', 'department') AND target_type IN ('space', 'node')
                AND level IN ('read', 'edit', 'manage')
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX x_files_location_default_unique
                ON x_files_spaces (ext_location_id)
                WHERE is_location_default = true AND ext_location_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION x_files_permission_references() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.subject_user_id := CASE WHEN NEW.subject_type = 'user' THEN NEW.subject_id END;
                NEW.subject_group_id := CASE WHEN NEW.subject_type = 'group' THEN NEW.subject_id END;
                NEW.subject_department_id := CASE WHEN NEW.subject_type = 'department' THEN NEW.subject_id END;
                NEW.target_space_id := CASE WHEN NEW.target_type = 'space' THEN NEW.target_id END;
                NEW.target_node_id := CASE WHEN NEW.target_type = 'node' THEN NEW.target_id END;
                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS x_files_location_default_unique');
        DB::table('x_files_permissions')->where('subject_type', 'department')->delete();
        DB::statement('ALTER TABLE x_files_permissions DROP CONSTRAINT x_files_permission_valid');
        DB::statement(<<<'SQL'
            ALTER TABLE x_files_permissions ADD CONSTRAINT x_files_permission_valid CHECK (
                subject_type IN ('user', 'group') AND target_type IN ('space', 'node')
                AND level IN ('read', 'edit', 'manage')
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_files_permission_references() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.subject_user_id := CASE WHEN NEW.subject_type = 'user' THEN NEW.subject_id END;
                NEW.subject_group_id := CASE WHEN NEW.subject_type = 'group' THEN NEW.subject_id END;
                NEW.target_space_id := CASE WHEN NEW.target_type = 'space' THEN NEW.target_id END;
                NEW.target_node_id := CASE WHEN NEW.target_type = 'node' THEN NEW.target_id END;
                RETURN NEW;
            END $$;
            SQL);

        Schema::table('x_files_permissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('subject_department_id'));
        Schema::table('x_files_spaces', fn (Blueprint $table) => $table->dropColumn('is_location_default'));
    }
};
