<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 2 (operations): retry/backoff for the deletion outbox, and a narrow, explicit prune
 * path for the append-only audit. Deleting audit rows needs the transaction-local flag
 * x_files.audit_prune and only reaches rows older than a day; updates stay forbidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_files_pending_deletions', function (Blueprint $table) {
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error', 255)->nullable();
        });
        Schema::table('x_files_audit_events', function (Blueprint $table) {
            $table->index(['created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_files_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('x_files.audit_prune', true) = 'on'
                    AND OLD.created_at < now() - interval '1 day' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'File audit events are append only';
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_files_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'File audit events are append only'; END $$;
            SQL);
        Schema::table('x_files_audit_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['actor_id', 'created_at']);
        });
        Schema::table('x_files_pending_deletions', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'next_attempt_at', 'last_error']);
        });
    }
};
