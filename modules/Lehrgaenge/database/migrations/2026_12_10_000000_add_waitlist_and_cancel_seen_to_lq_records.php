<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * waitlisted_at: the Ausbildungsleiter has registered the Interesse in fox112 (Warteliste).
     * cancel_seen_at: the Ausbildungsleiter has dealt with an Absage (signed off in fox112).
     */
    public function up(): void
    {
        Schema::table('lq_records', function (Blueprint $table) {
            $table->timestamp('waitlisted_at')->nullable();
            $table->timestamp('cancel_seen_at')->nullable();
        });

    }

    public function down(): void
    {
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropColumn(['waitlisted_at', 'cancel_seen_at']));
    }
};
