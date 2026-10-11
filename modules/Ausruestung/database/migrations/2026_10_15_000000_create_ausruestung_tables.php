<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kinds of equipment with their default service life and inspection interval
        Schema::create('ar_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('lifetime_years')->nullable();
            $table->unsignedSmallInteger('inspection_interval_months')->nullable();
            $table->string('lifetime_basis')->nullable(); // where the defaults come from, shown as a hint
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Gerätehaus, Lager, Fahrzeug …
        Schema::create('ar_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind', 20)->default('room'); // room | vehicle
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Geräteräume of a vehicle (G1, G2, Dach …) or shelves of a room
        Schema::create('ar_compartments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('ar_locations')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ar_equipment', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('ar_categories')->nullOnDelete();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('batch_number')->nullable();
            $table->string('barcode')->nullable(); // manufacturer's barcode, scannable like the code
            $table->date('manufactured_at')->nullable();
            $table->date('commissioned_at')->nullable();
            $table->date('purchased_at')->nullable();
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->unsignedSmallInteger('lifetime_years')->nullable(); // overrides the category
            $table->unsignedSmallInteger('inspection_interval_months')->nullable(); // overrides the category
            $table->date('next_inspection_at')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('ar_locations')->nullOnDelete();
            $table->foreignId('compartment_id')->nullable()->constrained('ar_compartments')->nullOnDelete();
            $table->string('status', 20)->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('next_inspection_at');
            $table->index(['manufacturer', 'model']);
            $table->index('barcode');
        });

        Schema::create('ar_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->date('inspected_at');
            $table->boolean('passed');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Internal lending: a reservation becomes a loan when the equipment is handed out
        Schema::create('ar_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20); // reserved | active | returned | cancelled
            $table->timestamp('starts_at');
            $table->timestamp('due_at');
            $table->timestamp('handed_out_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('overdue_reminded_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'status']);
        });

        // Damage reports and their repair
        Schema::create('ar_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->string('status', 20)->default('open'); // open | in_repair | repaired | scrapped
            $table->text('repair_notes')->nullable();
            $table->decimal('repair_cost', 10, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Manufacturer recalls; matching equipment is locked until the recall is resolved for it
        Schema::create('ar_recalls', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('manufacturer');
            $table->string('model')->nullable();
            $table->string('serial_from')->nullable();
            $table->string('serial_to')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('manufactured_from')->nullable();
            $table->date('manufactured_to')->nullable();
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->date('issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ar_recall_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recall_id')->constrained('ar_recalls')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->string('previous_status', 20);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();

            $table->unique(['recall_id', 'equipment_id']);
        });

        // Soll-Beladung: how many items of a category belong into a compartment
        Schema::create('ar_load_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compartment_id')->constrained('ar_compartments')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('ar_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['compartment_id', 'category_id']);
        });

        // History per item: Einsätze, Übungen, Prüfungen, Ausleihe, Schäden, Rückrufe, Umzüge
        Schema::create('ar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->timestamp('occurred_at');
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'occurred_at']);
        });

        $now = now();
        DB::table('ar_categories')->insert(collect(self::DEFAULT_CATEGORIES)->map(fn ($c, $i) => $c + [
            'sort_order' => $i + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * Starting values for common equipment. They are suggestions to be checked against the
     * manufacturer's instructions and the DGUV rules in force; every value can be edited.
     */
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Feuerwehrhelm', 'lifetime_years' => 10, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; jährliche Sichtprüfung'],
        ['name' => 'Pressluftatmer', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Wartung nach Herstellervorgaben; Grundüberholung laut Hersteller'],
        ['name' => 'Atemschutzmaske', 'lifetime_years' => null, 'inspection_interval_months' => 24, 'lifetime_basis' => 'Prüfung nach Herstellervorgaben'],
        ['name' => 'Druckluftflasche (Composite)', 'lifetime_years' => 20, 'inspection_interval_months' => 60, 'lifetime_basis' => 'Lebensdauer laut Flaschenstempel; wiederkehrende Prüfung'],
        ['name' => 'Druckluftflasche (Stahl)', 'lifetime_years' => null, 'inspection_interval_months' => 120, 'lifetime_basis' => 'Wiederkehrende Prüfung laut Flaschenstempel'],
        ['name' => 'Feuerwehrleine', 'lifetime_years' => 10, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; Prüfung nach Gebrauch und jährlich'],
        ['name' => 'Auffang- und Haltegurt', 'lifetime_years' => 10, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; jährliche Prüfung durch Sachkundigen'],
        ['name' => 'Tragbare Leiter', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Jährliche Prüfung'],
        ['name' => 'Hydraulisches Rettungsgerät', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Jährliche Prüfung nach Herstellervorgaben'],
        ['name' => 'Chemikalienschutzanzug', 'lifetime_years' => 10, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; Dichtprüfung'],
        ['name' => 'Elektrisches Gerät', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Prüfung ortsveränderlicher elektrischer Betriebsmittel'],
        ['name' => 'Sonstiges', 'lifetime_years' => null, 'inspection_interval_months' => null, 'lifetime_basis' => null],
    ];

    public function down(): void
    {
        foreach (['ar_events', 'ar_load_plan_items', 'ar_recall_equipment', 'ar_recalls', 'ar_damages', 'ar_loans', 'ar_inspections', 'ar_equipment', 'ar_compartments', 'ar_locations', 'ar_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
