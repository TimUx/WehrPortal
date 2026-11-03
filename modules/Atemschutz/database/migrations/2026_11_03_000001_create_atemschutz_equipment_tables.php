<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('as_equipment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('family', 30);
            $table->unsignedSmallInteger('minimum_fill_pressure_bar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['family', 'is_active']);
        });

        $now = now();
        DB::table('as_equipment_types')->insert([
            ['code' => 'atemschutzmaske', 'name' => 'Atemschutzmaske', 'family' => 'mask', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'pressluftatmer', 'name' => 'Pressluftatmer', 'family' => 'apparatus', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'atemluftflasche-stahl', 'name' => 'Atemluftflasche (Stahl)', 'family' => 'cylinder', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'atemluftflasche-composite', 'name' => 'Atemluftflasche (Composite)', 'family' => 'cylinder', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'chemikalienschutzanzug', 'name' => 'Chemikalienschutzanzug', 'family' => 'suit', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('as_equipment_type_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained('as_equipment_types')->restrictOnDelete();
            $table->string('from_name', 120);
            $table->string('to_name', 120);
            $table->unsignedSmallInteger('from_minimum_fill_pressure_bar')->nullable();
            $table->unsignedSmallInteger('to_minimum_fill_pressure_bar')->nullable();
            $table->boolean('from_is_active');
            $table->boolean('to_is_active');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');
            $table->timestamps();
            $table->index(['type_id', 'changed_at']);
        });

        Schema::create('as_equipment', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('device_number', 80)->nullable()->unique();
            $table->string('name', 180);
            $table->foreignId('type_id')->constrained('as_equipment_types')->restrictOnDelete();
            $table->string('manufacturer', 150)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('serial_number', 150)->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('commissioned_at')->nullable();
            $table->date('purchased_at')->nullable();
            $table->string('status', 30)->default('available');
            $table->unsignedSmallInteger('fill_pressure_bar')->nullable();
            $table->date('last_filled_on')->nullable();
            $table->date('next_inspection_at')->nullable();
            $table->text('inspection_basis')->nullable();
            $table->jsonb('specifications')->default('{}');
            $table->string('rfid', 100)->nullable()->unique();
            $table->unsignedBigInteger('ext_location_id')->nullable();
            $table->string('physical_location', 180)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['type_id', 'status']);
            $table->index(['ext_location_id', 'physical_location']);
            $table->index('next_inspection_at');
        });

        Schema::create('as_equipment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('as_equipment')->cascadeOnDelete();
            $table->foreignId('component_equipment_id')->unique()->constrained('as_equipment')->cascadeOnDelete();
            $table->string('role', 80);
            $table->timestamps();
            $table->unique(['equipment_id', 'component_equipment_id']);
            $table->index('equipment_id');
        });
        DB::statement('ALTER TABLE as_equipment_components ADD CONSTRAINT as_equipment_components_not_self CHECK (equipment_id <> component_equipment_id)');

        Schema::create('as_equipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('as_equipment')->restrictOnDelete();
            $table->date('performed_on');
            $table->date('next_due_on')->nullable();
            $table->string('result', 12);
            $table->jsonb('checklist')->default('[]');
            $table->text('findings')->nullable();
            $table->string('inspector_name', 150);
            $table->string('qualification', 150);
            $table->string('inspector_number', 80)->nullable();
            $table->string('provider_name', 150)->nullable();
            $table->text('signature');
            $table->timestampTz('signed_at');
            $table->char('content_hash', 64);
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['equipment_id', 'performed_on']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION as_equipment_inspection_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN
                    RAISE EXCEPTION 'Signed Atemschutz inspection records are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER as_equipment_inspections_immutable BEFORE UPDATE OR DELETE ON as_equipment_inspections FOR EACH ROW EXECUTE FUNCTION as_equipment_inspection_immutable()');

        Schema::create('as_equipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('as_equipment')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('from_organization', 180)->nullable();
            $table->string('to_organization', 180)->nullable();
            $table->string('from_location', 180)->nullable();
            $table->string('to_location', 180)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->index(['equipment_id', 'occurred_at']);
        });

        Schema::create('as_equipment_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('as_equipment')->restrictOnDelete();
            $table->string('title', 180);
            $table->date('due_on');
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained('as_equipment_inspections')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('as_equipment_tasks');
        Schema::dropIfExists('as_equipment_events');
        DB::unprepared('DROP TRIGGER IF EXISTS as_equipment_inspections_immutable ON as_equipment_inspections');
        Schema::dropIfExists('as_equipment_inspections');
        DB::unprepared('DROP FUNCTION IF EXISTS as_equipment_inspection_immutable()');
        DB::statement('ALTER TABLE IF EXISTS as_equipment_components DROP CONSTRAINT IF EXISTS as_equipment_components_not_self');
        Schema::dropIfExists('as_equipment_components');
        Schema::dropIfExists('as_equipment');
        Schema::dropIfExists('as_equipment_type_events');
        Schema::dropIfExists('as_equipment_types');
    }
};
