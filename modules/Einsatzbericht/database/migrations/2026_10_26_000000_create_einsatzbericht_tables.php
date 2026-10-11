<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Einsatzberichte with vehicles and crew; kept permanently */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eb_reports', function (Blueprint $table) {
            $table->id();
            // Kopfdaten
            $table->string('number', 20)->unique();
            $table->string('dispatch_number', 50)->nullable();
            $table->string('keyword', 150)->nullable();
            // draft | closed | released
            $table->string('status', 20)->default('draft')->index();
            // Zeiten
            $table->timestamp('alarmed_at')->nullable()->index();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            // Einsatzstelle
            $table->string('address', 255)->nullable();
            $table->string('object', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('affected_parties')->nullable();
            // Einsatzart
            $table->string('category', 30)->nullable()->index();
            // Lage und Maßnahmen
            $table->text('situation')->nullable();
            $table->text('measures')->nullable();
            $table->text('equipment_used')->nullable();
            // Personen und Schäden
            $table->unsignedSmallInteger('rescued')->default(0);
            $table->unsignedSmallInteger('injured')->default(0);
            $table->unsignedSmallInteger('dead')->default(0);
            $table->text('property_damage')->nullable();
            $table->text('environmental_damage')->nullable();
            // Beteiligte
            $table->boolean('police')->default(false);
            $table->boolean('ems')->default(false);
            $table->text('neighbor_brigades')->nullable();
            $table->text('other_parties')->nullable();
            // Abschluss
            $table->text('handover')->nullable();
            $table->boolean('fire_watch')->default(false);
            $table->text('fire_watch_notes')->nullable();
            $table->foreignId('commander_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A vehicle on the Einsatz; vehicle_id null = Bereitschaft at the Gerätehaus
        Schema::create('eb_report_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('name', 100);
            $table->boolean('is_standby')->default(false);
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Every Einsatzkraft on exactly one vehicle or in the Bereitschaft
        Schema::create('eb_report_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->foreignId('report_vehicle_id')->constrained('eb_report_vehicles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('function', 40)->default('einsatzkraft');
            $table->boolean('breathing_apparatus')->default(false);
            $table->timestamps();
            $table->unique(['report_id', 'user_id']);
        });

        DB::table('modules')->upsert(
            [['key' => 'einsatzbericht', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'einsatzbericht')->delete();
        Schema::dropIfExists('eb_report_crew');
        Schema::dropIfExists('eb_report_vehicles');
        Schema::dropIfExists('eb_reports');
    }
};
