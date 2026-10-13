<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFID/NFC tag per item (scannable like code and barcode), when it was last seen at an Inventur,
 * costs of Reinigungen and Wartungen in the history, and the Inventuren themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ar_equipment', function (Blueprint $table) {
            $table->string('rfid', 64)->nullable()->unique();   // tag UID, upper case without separators
            $table->timestampTz('last_seen_at')->nullable();    // found at an Inventur
        });

        Schema::table('ar_events', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable();
        });

        Schema::create('ar_stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained('ar_locations')->nullOnDelete();
            $table->string('location_name', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('expected');
            $table->json('found');      // equipment ids that were there
            $table->json('missing');    // expected but not found: [{id, code, name}]
            $table->json('moved');      // found here but booked elsewhere, moved here: [{id, code, name, from}]
            $table->json('unknown');    // scanned codes nobody knows
            $table->text('notes')->nullable();
            $table->timestampTz('finished_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_stocktakes');
        Schema::table('ar_events', fn (Blueprint $t) => $t->dropColumn('cost'));
        Schema::table('ar_equipment', fn (Blueprint $t) => $t->dropColumn(['rfid', 'last_seen_at']));
    }
};
