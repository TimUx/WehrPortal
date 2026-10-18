<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 4 (Office editing): versions saved by the office server (source "office", with the
 * session and whether it was an autosave), WOPI locks per file, and sessions that survive the
 * replacement of an autosave version (version_id is set null instead of deleting the session).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE x_files_versions DROP CONSTRAINT IF EXISTS x_files_version_valid');
        DB::statement("ALTER TABLE x_files_versions ADD CONSTRAINT x_files_version_valid CHECK (
            number > 0 AND size >= 0 AND checksum ~ '^[0-9a-f]{64}$'
            AND storage_key ~ '^[0-9a-f-]{36}/[0-9a-f-]{36}$'
            AND source IN ('upload', 'restore', 'office')
        )");
        Schema::table('x_files_versions', function (Blueprint $table) {
            // No foreign key: sessions are pruned, versions are immutable
            $table->unsignedBigInteger('office_session_id')->nullable();
            $table->boolean('autosave')->default(false);
        });
        Schema::table('x_files_office_sessions', function (Blueprint $table) {
            $table->dropForeign(['version_id']);
        });
        Schema::table('x_files_office_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('version_id')->nullable()->change();
            $table->foreign('version_id')->references('id')->on('x_files_versions')->nullOnDelete();
        });
        Schema::create('x_files_office_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('x_files_nodes')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('x_files_office_sessions')->nullOnDelete();
            $table->string('lock_value', 1024);
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_files_office_locks');
        Schema::table('x_files_office_sessions', function (Blueprint $table) {
            $table->dropForeign(['version_id']);
        });
        DB::table('x_files_office_sessions')->whereNull('version_id')->delete();
        Schema::table('x_files_office_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('version_id')->nullable(false)->change();
            $table->foreign('version_id')->references('id')->on('x_files_versions')->cascadeOnDelete();
        });
        Schema::table('x_files_versions', function (Blueprint $table) {
            $table->dropColumn(['office_session_id', 'autosave']);
        });
        DB::statement('ALTER TABLE x_files_versions DROP CONSTRAINT IF EXISTS x_files_version_valid');
        DB::statement("ALTER TABLE x_files_versions ADD CONSTRAINT x_files_version_valid CHECK (
            number > 0 AND size >= 0 AND checksum ~ '^[0-9a-f]{64}$'
            AND storage_key ~ '^[0-9a-f-]{36}/[0-9a-f-]{36}$'
            AND source IN ('upload', 'restore', 'office')
        ) NOT VALID");
    }
};
