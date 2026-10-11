<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fahrzeugverwaltung & Flotte: a profile for each vehicle of the shared vehicle list (the one the
 * Fahrzeugbuchung and the Einsatzbericht use), its status, Werkstatt and other downtimes that
 * block bookings, deadlines (HU, SP, UVV, Pumpe …) and the documented Wartungen and repairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fv_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->unique()->constrained('vehicles')->cascadeOnDelete();
            $table->string('call_sign', 50)->nullable();          // Funkrufname
            $table->string('license_plate', 20)->nullable();
            $table->string('category', 20)->nullable();
            $table->string('vehicle_type', 80)->nullable();       // e.g. HLF 20
            $table->string('chassis', 100)->nullable();           // Fahrgestell
            $table->string('body_builder', 100)->nullable();      // Aufbau
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->date('first_registration')->nullable();
            $table->string('vin', 30)->nullable();
            $table->unsignedInteger('gross_weight_kg')->nullable();
            $table->string('crew', 20)->nullable();               // Besatzung, e.g. 1/8
            $table->string('license_class', 30)->nullable();
            $table->unsignedInteger('water_liters')->nullable();
            $table->unsignedInteger('foam_liters')->nullable();
            $table->string('pump', 80)->nullable();
            $table->json('features')->nullable();                 // Ausstattung
            $table->text('notes')->nullable();
            $table->longText('photo')->nullable();                // WebP as base64
            $table->timestampTz('photo_updated_at')->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->decimal('operating_hours', 8, 1)->nullable();
            $table->date('mileage_on')->nullable();
            $table->string('status', 12)->default('ready');       // ready | limited | unavailable
            $table->string('status_note', 300)->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            // Its place in the Ausrüstungsverwaltung (Beladung)
            $table->foreignId('location_id')->nullable()->constrained('ar_locations')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fv_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->index(['vehicle_id', 'created_at']);
        });

        // Werkstatt and other times the vehicle can't be used; ends_at null = until further notice
        Schema::create('fv_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('reason', 200);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'starts_at']);
        });

        Schema::create('fv_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('title', 150);
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->date('due_on');
            $table->unsignedSmallInteger('lead_days')->default(30);
            $table->string('notes', 500)->nullable();
            $table->timestampTz('due_mailed_at')->nullable();
            $table->timestampTz('overdue_mailed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'due_on']);
        });

        Schema::create('fv_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('deadline_id')->nullable()->constrained('fv_deadlines')->nullOnDelete();
            $table->string('kind', 12);                           // inspection | service | repair | other
            $table->string('title', 150);
            $table->date('done_on');
            $table->unsignedInteger('mileage_km')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->string('provider', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'done_on']);
        });

        Schema::create('fv_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('fv_records')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamp('created_at')->nullable();
        });

        DB::table('modules')->upsert(
            [['key' => 'fahrzeuge', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'fahrzeuge')->delete();
        foreach (['fv_attachments', 'fv_records', 'fv_deadlines', 'fv_downtimes', 'fv_status_events', 'fv_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
