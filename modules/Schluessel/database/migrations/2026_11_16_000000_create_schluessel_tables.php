<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schlüsselverwaltung: every key, transponder or card with what it opens (a shared place, a
 * vehicle, an object of the Objektpläne), where it is kept, and every handover with the signed
 * Schlüsselquittung. One open handover per key is enforced by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sk_keys', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();                 // SL-0001, on the label
            $table->string('name', 150);                          // "Gerätehaus Haupteingang"
            $table->string('kind', 20)->default('schluessel');
            $table->string('locking', 150)->nullable();           // Schließanlage / Schließgruppe
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('object_id')->nullable()->constrained('op_objects')->nullOnDelete();
            $table->string('storage', 150)->nullable();           // where it is kept when not issued
            $table->string('status', 12)->default('available');  // available | lost | retired (issued = open handover)
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('sk_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('key_id')->constrained('sk_keys')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('issued_at');
            $table->date('due_on')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->longText('signature')->nullable();            // Schlüsselquittung, PNG as base64
            $table->timestampTz('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'returned_at']);
        });
        DB::statement('CREATE UNIQUE INDEX sk_handovers_one_open ON sk_handovers (key_id) WHERE returned_at IS NULL');

        DB::table('modules')->upsert(
            [['key' => 'schluessel', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'schluessel')->delete();
        Schema::dropIfExists('sk_handovers');
        Schema::dropIfExists('sk_keys');
    }
};
