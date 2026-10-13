<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rückfragen to the reporter, photos on comments, a Zuständiger per object, defects on Geräte of
 * the Ausrüstungsverwaltung (with their Schaden there) and the reminder bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mm_defects', function (Blueprint $table) {
            $table->timestampTz('question_open_at')->nullable();   // a Rückfrage waits for the reporter
            $table->unsignedBigInteger('equipment_id')->nullable()->index();  // ar_equipment, when Ausrüstung is used
            $table->unsignedBigInteger('ar_damage_id')->nullable();           // the Schaden created there
            $table->timestampTz('reminded_at')->nullable();
        });

        Schema::table('mm_defect_photos', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained('mm_defect_events')->cascadeOnDelete();
        });

        Schema::table('mm_devices', function (Blueprint $table) {
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mm_devices', fn (Blueprint $t) => $t->dropConstrainedForeignId('responsible_id'));
        Schema::table('mm_defect_photos', fn (Blueprint $t) => $t->dropConstrainedForeignId('event_id'));
        Schema::table('mm_defects', fn (Blueprint $t) => $t->dropColumn(['question_open_at', 'equipment_id', 'ar_damage_id', 'reminded_at']));
    }
};
