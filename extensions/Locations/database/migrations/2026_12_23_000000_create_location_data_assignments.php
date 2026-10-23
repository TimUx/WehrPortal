<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_location_data_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('target_table', 32);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('location_id')->constrained('x_locations')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('assigned_at');
            $table->uuid('batch_id');
            $table->char('state_hash', 64);
            $table->unique(['target_table', 'target_id']);
        });
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_target CHECK (target_table IN ('x_att_lists', 'x_att_templates') AND target_id > 0)");
        DB::statement('ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_reason CHECK (length(trim(reason)) BETWEEN 1 AND 500)');
        DB::statement("ALTER TABLE x_location_data_assignments ADD CONSTRAINT x_location_data_assignments_hash CHECK (state_hash ~ '^[a-f0-9]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_location_data_assignments_history() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Location data assignment history is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER x_location_data_assignments_history BEFORE UPDATE OR DELETE ON x_location_data_assignments
                FOR EACH ROW EXECUTE FUNCTION x_location_data_assignments_history();
            CREATE TRIGGER x_location_data_assignments_no_truncate BEFORE TRUNCATE ON x_location_data_assignments
                FOR EACH STATEMENT EXECUTE FUNCTION x_location_data_assignments_history();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('x_location_data_assignments');
        DB::statement('DROP FUNCTION IF EXISTS x_location_data_assignments_history()');
    }
};
