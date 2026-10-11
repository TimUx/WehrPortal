<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('purpose');
            $table->string('destination')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'starts_at']);
        });

        // Exclusion constraint to prevent double-booking.
        // Uses half-open intervals [starts_at, ends_at) so that a booking ending
        // at 14:00 does NOT conflict with one starting at 14:00.
        // Only applies to non-cancelled bookings (WHERE cancelled_at IS NULL).
        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                vehicle_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (cancelled_at IS NULL)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
