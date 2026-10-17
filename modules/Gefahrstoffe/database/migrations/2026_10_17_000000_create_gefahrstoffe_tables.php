<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gf_materials', function (Blueprint $table) {
            $table->id();
            $table->string('un_number', 4)->unique();
            $table->string('name', 255);
            $table->text('synonyms')->nullable();
            $table->text('description')->nullable();
            $table->string('adr_class', 30)->nullable();
            $table->string('packing_group', 20)->nullable();
            $table->string('hazard_number', 20)->nullable();
            $table->jsonb('hazard_labels')->default('[]');
            $table->jsonb('ghs_pictograms')->default('[]');
            $table->text('dangers')->nullable();
            $table->text('first_aid')->nullable();
            $table->text('firefighting')->nullable();
            $table->text('spillage')->nullable();
            $table->string('source_name', 255);
            $table->string('source_version', 100)->nullable();
            $table->text('source_reference')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gf_materials');
    }
};
