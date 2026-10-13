<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unterweisungen (topics, e.g. UVV, Einsatzstellenhygiene) and Einweisungen (on a vehicle or a device):
     * who needs which, when it was held, who took part and signed – the proof for the DGUV.
     */
    public function up(): void
    {
        Schema::create('uw_topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('short', 12)->nullable();                // Kürzel next to the name, e.g. "UVV"
            $table->string('kind', 12)->default('unterweisung');     // unterweisung | einweisung
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('counts_as', 20)->nullable();            // atemschutz: counts as Unterweisung (FwDV 7)
            $table->text('description')->nullable();
            $table->boolean('for_all')->default(false);              // every active Kamerad needs it
            $table->json('group_ids')->nullable();                   // or members of these Abteilungen
            $table->json('function_ids')->nullable();                // or holders of these Funktionen
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('uw_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('uw_topics')->restrictOnDelete();
            $table->date('held_on');
            $table->string('instructor_name', 150);
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['topic_id', 'held_on']);
        });

        Schema::create('uw_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('uw_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('signature')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->date('reminded_on')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'user_id']);
        });

        Schema::create('uw_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('uw_sessions')->cascadeOnDelete();
            $table->string('filename', 200);
            $table->string('mime', 150);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamps();
        });

        DB::table('modules')->upsert(
            [['key' => 'unterweisungen', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        foreach (['uw_files', 'uw_attendances', 'uw_sessions', 'uw_topics'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('modules')->where('key', 'unterweisungen')->delete();
    }
};
