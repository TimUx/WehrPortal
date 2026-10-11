<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender, stage 2: target group by Funktion (besides Abteilung and single Kameraden), reusable
 * Terminvorlagen, and the Terminabfrage: several date proposals, everyone ticks yes/maybe/no, the
 * Terminverwalter fixes the best one as an event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kal_event_function', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('kal_events')->cascadeOnDelete();
            $table->foreignId('function_id')->constrained('member_functions')->cascadeOnDelete();
            $table->primary(['event_id', 'function_id']);
        });

        Schema::create('kal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->json('data');               // title, type, times, place, Rückmeldung, target group …
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kal_polls', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('type', 20)->default('veranstaltung');
            $table->string('location', 150)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('kal_events')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['group' => 'groups', 'function' => 'member_functions', 'user' => 'users'] as $name => $target) {
            Schema::create("kal_poll_{$name}", function (Blueprint $table) use ($name, $target) {
                $table->foreignId('poll_id')->constrained('kal_polls')->cascadeOnDelete();
                $table->foreignId("{$name}_id")->constrained($target)->cascadeOnDelete();
                $table->primary(['poll_id', "{$name}_id"]);
            });
        }

        Schema::create('kal_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('kal_polls')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->index(['poll_id', 'starts_at']);
        });

        Schema::create('kal_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained('kal_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('answer', 5);        // yes | maybe | no
            $table->timestamps();
            $table->unique(['option_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['kal_poll_votes', 'kal_poll_options', 'kal_poll_user', 'kal_poll_function', 'kal_poll_group', 'kal_polls', 'kal_templates', 'kal_event_function'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
