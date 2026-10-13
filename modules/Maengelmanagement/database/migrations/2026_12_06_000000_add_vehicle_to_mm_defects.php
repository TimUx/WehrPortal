<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A defect can concern a vehicle of the shared list (Fahrzeugakte, Prüfungen of the Fahrzeugverwaltung) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mm_defects', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('device_id')->constrained('vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mm_defects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });
    }
};
