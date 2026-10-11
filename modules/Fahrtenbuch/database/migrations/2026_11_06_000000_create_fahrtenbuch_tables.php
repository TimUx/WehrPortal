<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fahrtenbuch: every trip of the shared vehicles (Fahrzeugbuchung, Fahrzeugverwaltung) with
 * driver, times, Kilometerstand, start, destination and purpose, linked to an Einsatz or a
 * booking; corrections are kept as revisions, nothing is deleted. Plus the fuel stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ft_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('start_km');
            $table->unsignedInteger('end_km')->nullable();
            $table->string('origin', 200);
            $table->string('destination', 200)->nullable();
            $table->string('purpose', 20);
            $table->string('purpose_note', 300)->nullable();
            $table->foreignId('report_id')->nullable()->constrained('eb_reports')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->unsignedSmallInteger('passengers')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'started_at']);
        });

        // One trip at a time per vehicle
        DB::statement('CREATE UNIQUE INDEX ft_trips_one_open ON ft_trips (vehicle_id) WHERE ended_at IS NULL AND voided_at IS NULL');

        Schema::create('ft_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('ft_trips')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 300);
            $table->json('before');
            $table->json('after');
            $table->timestampTz('created_at');
        });

        Schema::create('ft_fuelings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('fueled_at');
            $table->unsignedInteger('km')->nullable();
            $table->decimal('liters', 7, 2);
            $table->unsignedInteger('cost_cents')->nullable();
            $table->string('fuel', 12)->default('diesel');
            $table->boolean('full_tank')->default(true);
            $table->string('station', 150)->nullable();
            $table->string('notes', 500)->nullable();
            $table->longText('receipt')->nullable();             // photo (WebP) or PDF as base64
            $table->string('receipt_mime', 100)->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'fueled_at']);
        });

        DB::table('modules')->upsert(
            [['key' => 'fahrtenbuch', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'fahrtenbuch')->delete();
        foreach (['ft_fuelings', 'ft_revisions', 'ft_trips'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
