<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self sign-up for Arbeitsdienste and Feste: a plan can let the Kameraden pick their places
 * themselves (until a deadline, optionally with approval), for a target group by Abteilung and/or
 * Funktion. Each place knows who put it there and whether it is still a request; a reminder goes
 * out the day before the shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->string('signup', 10)->default('planner')->after('status');   // planner | self
            $table->timestamp('signup_until')->nullable()->after('signup');
            $table->boolean('needs_approval')->default(false)->after('signup_until');
            $table->unsignedSmallInteger('max_shifts')->nullable()->after('needs_approval');
            $table->json('audience_groups')->nullable()->after('max_shifts');
            $table->json('audience_functions')->nullable()->after('audience_groups');
        });

        Schema::table('sp_assignments', function (Blueprint $table) {
            $table->string('status', 10)->default('confirmed')->after('locked');  // confirmed | requested
            $table->foreignId('signed_up_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable()->after('signed_up_by');
        });
    }

    public function down(): void
    {
        Schema::table('sp_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_up_by');
            $table->dropColumn(['status', 'reminded_at']);
        });
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->dropColumn(['signup', 'signup_until', 'needs_approval', 'max_shifts', 'audience_groups', 'audience_functions']);
        });
    }
};
