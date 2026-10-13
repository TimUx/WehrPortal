<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Termine get a saved place (address and navigation) and the Kleidung asked for, both from the
 * lists the modules share. managed_by names the module that keeps an event in sync (Übungsdienst-
 * planer, Schichtplan), so the Kalender can say where to change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kal_events', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->json('dress_code_ids')->nullable();
            $table->string('managed_by', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kal_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
            $table->dropColumn(['dress_code_ids', 'managed_by']);
        });
    }
};
