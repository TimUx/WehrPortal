<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shift plan for an event, e.g. the Pommesstand of the Jugendfeuerwehr at the village fair
        Schema::create('sp_plans', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('shift_minutes')->default(60);
            $table->unsignedSmallInteger('max_consecutive')->default(2);
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->timestamp('mailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sp_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sp_plans')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Time blocks of a plan, generated from start, end and shift length
        Schema::create('sp_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sp_plans')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });

        Schema::create('sp_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sp_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'user_id']);
        });

        // Who works where in which block; locked ones were set by hand and survive a redistribution
        Schema::create('sp_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sp_plans')->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('sp_slots')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('sp_stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('locked')->default(false);
            $table->timestamps();

            // Nobody stands at two stations at once
            $table->unique(['slot_id', 'user_id']);
        });

        // Reusable station sets, e.g. "Pommesstand JF" or "Grillstand Aktive"
        Schema::create('sp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('stations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['sp_templates', 'sp_assignments', 'sp_participants', 'sp_slots', 'sp_stations', 'sp_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
