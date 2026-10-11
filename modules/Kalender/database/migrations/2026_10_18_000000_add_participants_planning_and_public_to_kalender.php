<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kal_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_participants')->nullable()->after('rsvp_deadline');
            $table->boolean('is_public')->default(false)->after('min_participants');
            $table->timestamp('reminder_sent_at')->nullable()->after('is_public');
        });

        // Kameraden invited individually, in addition to (or instead of) whole Abteilungen
        Schema::create('kal_event_user', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('kal_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['event_id', 'user_id']);
        });

        // A row may now carry only the attendance, and a Terminverwalter may answer for someone
        Schema::table('kal_responses', function (Blueprint $table) {
            $table->string('status', 10)->nullable()->change();
            $table->boolean('attended')->nullable()->after('comment');
            $table->foreignId('set_by')->nullable()->after('attended')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kal_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('set_by');
            $table->dropColumn('attended');
        });
        Schema::dropIfExists('kal_event_user');
        Schema::table('kal_events', fn (Blueprint $table) => $table->dropColumn(['min_participants', 'is_public', 'reminder_sent_at']));
    }
};
