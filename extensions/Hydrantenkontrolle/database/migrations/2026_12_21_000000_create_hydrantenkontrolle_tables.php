<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function location(Blueprint $table): void
    {
        if (Schema::hasTable('x_locations')) {
            $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
        } else {
            $table->unsignedBigInteger('ext_location_id')->nullable();
        }
    }

    public function up(): void
    {
        Schema::create('x_hc_hydrants', function (Blueprint $table) {
            $table->id();
            $this->location($table);
            $table->string('number', 100);
            $table->string('street')->nullable();
            $table->string('house_number', 50)->nullable();
            $table->text('location_description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX x_hc_hydrants_location_number_unique ON x_hc_hydrants (ext_location_id, number) NULLS NOT DISTINCT');

        Schema::create('x_hc_years', function (Blueprint $table) {
            $table->id();
            $this->location($table);
            $table->unsignedSmallInteger('year');
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX x_hc_years_location_year_unique ON x_hc_years (ext_location_id, year) NULLS NOT DISTINCT');

        Schema::create('x_hc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_year_id')->constrained('x_hc_years')->restrictOnDelete();
            $table->foreignId('hydrant_id')->constrained('x_hc_hydrants')->restrictOnDelete();
            $table->json('snapshot');
            $table->date('checked_at')->nullable();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspector_name')->nullable();
            $table->json('defects')->default('[]');
            $table->boolean('is_ok')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['inspection_year_id', 'hydrant_id']);
        });

        DB::table('modules')->upsert(
            [['key' => 'hydrantenkontrolle', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'hydrantenkontrolle')->delete();
        Schema::dropIfExists('x_hc_inspections');
        Schema::dropIfExists('x_hc_years');
        Schema::dropIfExists('x_hc_hydrants');
    }
};
