<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other calendars pulled in by URL (iCal), e.g. Kreisfeuerwehrverband or school holidays
        Schema::create('kal_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 1000);
            $table->string('type', 20)->default('sonstiges');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('kal_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20)->default('uebung');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->boolean('rsvp')->default(false);
            $table->timestamp('rsvp_deadline')->nullable();
            $table->string('series_id', 36)->nullable(); // events created together as a repetition
            $table->foreignId('source_id')->nullable()->constrained('kal_sources')->cascadeOnDelete();
            $table->string('external_uid')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_at');
            $table->index('series_id');
            $table->unique(['source_id', 'external_uid']);
        });

        // Audience: no rows = everyone with access to the module, otherwise these Abteilungen
        Schema::create('kal_event_group', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('kal_events')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->primary(['event_id', 'group_id']);
        });

        // Rückmeldung: zugesagt, abgesagt, vielleicht
        Schema::create('kal_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('kal_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10);
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });

        // Personal subscription link for phone calendars (read-only iCal feed)
        Schema::create('kal_feed_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['kal_feed_tokens', 'kal_responses', 'kal_event_group', 'kal_events', 'kal_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
