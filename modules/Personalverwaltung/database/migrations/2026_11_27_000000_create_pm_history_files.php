<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Nachweise (PDF or photo) attached to a stage or event of the Diensthistorie, kept in the database */
    public function up(): void
    {
        Schema::create('pm_history_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('pm_history')->cascadeOnDelete();
            $table->string('filename', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_history_files');
    }
};
