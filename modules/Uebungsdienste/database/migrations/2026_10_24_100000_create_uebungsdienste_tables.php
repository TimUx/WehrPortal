<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Übungsdienste for the Einsatzabteilung: date, start, topic, place and who is responsible */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ud_services', function (Blueprint $table) {
            $table->id();
            // Local Berlin date and time as planned; no time zone conversion needed
            $table->date('date')->index();
            $table->string('starts_at', 5)->nullable();
            $table->string('location', 255)->nullable();
            $table->text('topic')->nullable();
            $table->timestamps();
        });

        Schema::create('ud_service_user', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained('ud_services')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_id', 'user_id']);
        });

        // Restricted to Wehrführung: switched off and nobody gets access automatically
        DB::table('modules')->upsert(
            [['key' => 'uebungsdienste', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'uebungsdienste')->delete();
        Schema::dropIfExists('ud_service_user');
        Schema::dropIfExists('ud_services');
    }
};
