<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deciders are now flagged per user instead of per group
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_decider')->default(false)->after('is_admin');
        });

        DB::statement('
            UPDATE users SET is_decider = true
            WHERE id IN (
                SELECT group_user.user_id FROM group_user
                JOIN groups ON groups.id = group_user.group_id
                WHERE groups.receives_escalations = true
            )
        ');

        DB::table('groups')->where('name', 'Stellv. Wehrführer')->delete();

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('receives_escalations');
        });

        // confirmed | pending (waiting for a decision) | rejected
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status', 20)->default('confirmed')->after('notes');
            $table->index('status');
        });

        // Only confirmed bookings block a vehicle; pending requests may overlap them
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');
        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                vehicle_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (cancelled_at IS NULL AND status = 'confirmed')
        ");

        Schema::create('booking_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->json('conflicting_booking_ids');
            $table->text('reason');
            // pending | approved | rejected | withdrawn
            $table->string('status', 20)->default('pending')->index();
            $table->json('notified_user_ids')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_decisions');

        DB::table('bookings')->where('status', '!=', 'confirmed')->update(['cancelled_at' => now()]);
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');
        DB::statement("
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                vehicle_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (cancelled_at IS NULL)
        ");
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('receives_escalations')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_decider');
        });
    }
};
