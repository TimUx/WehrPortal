<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stage 3: QR access for external Wehren at large Einsätze – status and Lagemeldungen without login */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eb_reports', function (Blueprint $table) {
            // The key in the QR address, encrypted so the Einsatzleiter can show it again; looked up by hash
            $table->text('external_key')->nullable();
            $table->string('external_key_hash', 64)->nullable()->unique();
        });

        // An external Wehr (one phone) that scanned the code
        Schema::create('eb_external_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->string('device_hash', 64);
            $table->string('name', 150);
            $table->string('vehicle', 100)->nullable();
            $table->string('strength', 20)->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'device_hash']);
        });

        Schema::create('eb_external_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('eb_external_units')->cascadeOnDelete();
            $table->string('status', 30);
            $table->timestamp('created_at');
        });

        Schema::table('eb_situation_messages', function (Blueprint $table) {
            $table->foreignId('external_unit_id')->nullable()->constrained('eb_external_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eb_situation_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('external_unit_id'));
        Schema::dropIfExists('eb_external_statuses');
        Schema::dropIfExists('eb_external_units');
        Schema::table('eb_reports', fn (Blueprint $table) => $table->dropColumn(['external_key', 'external_key_hash']));
    }
};
