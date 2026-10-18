<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 (public links): read-only links for people without an account. Off by default:
 * the admin switch and the per-space flag must both be on. Only the SHA-256 of the token is
 * stored; an expiry date is required, password (hashed) and download limit are optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_files_spaces', function (Blueprint $table) {
            $table->boolean('public_shares')->default(false);
        });
        Schema::create('x_files_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('label', 150)->nullable();
            $table->string('password_hash')->nullable();
            $table->integer('max_downloads')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_access_at')->nullable();
            $table->timestampsTz();
            $table->index(['node_id', 'expires_at']);
        });
        DB::statement('ALTER TABLE x_files_shares ADD CONSTRAINT x_files_share_valid CHECK (
            download_count >= 0 AND (max_downloads IS NULL OR max_downloads > 0) AND expires_at > created_at
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('x_files_shares');
        Schema::table('x_files_spaces', function (Blueprint $table) {
            $table->dropColumn('public_shares');
        });
    }
};
