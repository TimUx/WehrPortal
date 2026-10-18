<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3 (Office): one row per opened document. Only the SHA-256 of the access token is
 * stored; the office server presents the raw token on every call and rights are checked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_files_office_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('x_files_versions')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('level', 10)->default('read');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->index(['node_id', 'expires_at']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_files_office_sessions');
    }
};
