<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Übungsdienst goes into the Kalender of its target group: end time, saved place, Kleidung,
 * Einsatzabteilungen of the target Standorte, and the event it is kept in sync with. The
 * topic stays in the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ud_services', function (Blueprint $table) {
            $table->string('ends_at', 5)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->json('dress_code_ids')->nullable();
            $table->json('group_ids')->nullable();
            $table->json('function_ids')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('kal_events')->nullOnDelete();
            // Was in the Kalender once: an event deleted there is not brought back by the hourly sync
            $table->timestampTz('calendar_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ud_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('place_id');
            $table->dropColumn(['ends_at', 'dress_code_ids', 'group_ids', 'function_ids', 'calendar_synced_at']);
        });
    }
};
