<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each Kamerad's own order of the modules on the start page, and which ones they hide there
        Schema::create('user_module_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'module_key']);
        });

        // Notices from the Wehrführung or admins on everyone's start page
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('important')->default(false);
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Audience: no rows = everyone, otherwise the members of these Abteilungen
        Schema::create('announcement_group', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->primary(['announcement_id', 'group_id']);
        });

        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['announcement_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_post_announcements')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_post_announcements'));
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcement_group');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('user_module_preferences');
    }
};
