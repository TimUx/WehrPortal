<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wasserkarte & Hydranten: the Löschwasserquellen (hydrants, ponds, wells, tanks, open water) with
 * position, status and performance data, their inspections and the defects reported by Kameraden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wk_sources', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable();             // e.g. H-012
            $table->string('kind', 20);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('location', 200)->nullable();         // "vor Hauptstraße 12"
            $table->string('status', 12)->default('available');  // available | defective | locked
            $table->string('status_note', 300)->nullable();
            $table->unsignedInteger('flow_lpm')->nullable();      // Durchfluss l/min
            $table->decimal('pressure_bar', 4, 1)->nullable();    // Ruhedruck
            $table->unsignedInteger('capacity_m3')->nullable();   // ponds, tanks
            $table->unsignedSmallInteger('pipe_dn')->nullable();  // Nennweite der Leitung
            $table->string('operator', 150)->nullable();          // Wasserversorger
            $table->unsignedSmallInteger('inspection_interval_months')->nullable();
            $table->date('last_inspected_on')->nullable();
            $table->date('next_inspection_on')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('due_mailed_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'status']);
            $table->index('next_inspection_on');
        });

        Schema::create('wk_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('wk_sources')->cascadeOnDelete();
            $table->date('inspected_on');
            $table->string('result', 10);                         // passed | defect
            $table->unsignedInteger('flow_lpm')->nullable();
            $table->decimal('static_pressure_bar', 4, 1)->nullable();
            $table->decimal('flow_pressure_bar', 4, 1)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_id', 'inspected_on']);
        });

        Schema::create('wk_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('wk_sources')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description', 1000);
            $table->longText('photo')->nullable();                // WebP as base64
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 500)->nullable();
            $table->timestamps();
            $table->index(['source_id', 'resolved_at']);
        });

        Schema::create('wk_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('wk_sources')->cascadeOnDelete();
            $table->string('status', 12);
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        DB::table('modules')->upsert(
            [['key' => 'wasserkarte', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'wasserkarte')->delete();
        foreach (['wk_status_events', 'wk_defects', 'wk_inspections', 'wk_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
