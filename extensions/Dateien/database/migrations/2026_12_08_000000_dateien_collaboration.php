<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6 (comfort): favourites, recently used, subscriptions, comments, a description per
 * entry, image previews (in the database, so they go with the version and never lie on disk as
 * unknown files) and a German full-text index over name and description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_files_nodes', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
        foreach (['x_files_favorites', 'x_files_subscriptions'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
                $table->timestampTz('created_at')->useCurrent();
                $table->primary(['user_id', 'node_id']);
                $table->index('node_id');
            });
        }
        Schema::create('x_files_recent', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
            $table->timestampTz('used_at');
            $table->primary(['user_id', 'node_id']);
            $table->index(['user_id', 'used_at']);
        });
        Schema::create('x_files_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('x_files_nodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestampsTz();
            $table->index(['node_id', 'created_at']);
        });
        Schema::create('x_files_previews', function (Blueprint $table) {
            $table->foreignId('version_id')->primary()->constrained('x_files_versions')->cascadeOnDelete();
            // base64 WebP/PNG, or empty when no preview can be made (so it is not tried again)
            $table->text('data');
            $table->string('mime', 30);
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("CREATE INDEX IF NOT EXISTS x_files_nodes_fulltext ON x_files_nodes USING gin (to_tsvector('german', name || ' ' || coalesce(description, '')))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS x_files_nodes_fulltext');
        Schema::dropIfExists('x_files_previews');
        Schema::dropIfExists('x_files_comments');
        Schema::dropIfExists('x_files_recent');
        Schema::dropIfExists('x_files_subscriptions');
        Schema::dropIfExists('x_files_favorites');
        Schema::table('x_files_nodes', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
