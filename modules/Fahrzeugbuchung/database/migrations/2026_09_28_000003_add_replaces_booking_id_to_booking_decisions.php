<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the request is a change of an existing booking; that booking is cancelled on approval
        Schema::table('booking_decisions', function (Blueprint $table) {
            $table->foreignId('replaces_booking_id')->nullable()->after('booking_id')
                ->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_booking_id');
        });
    }
};
