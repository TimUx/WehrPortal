<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('as_equipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('as_equipment')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('title', 180);
            $table->string('filename', 255);
            $table->unsignedBigInteger('size');
            $table->longText('data');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['equipment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('as_equipment_documents');
    }
};
