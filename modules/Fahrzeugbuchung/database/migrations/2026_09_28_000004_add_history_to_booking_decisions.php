<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail: [{at, by, by_name, action, note}]
        Schema::table('booking_decisions', function (Blueprint $table) {
            $table->json('history')->nullable()->after('decision_note');
        });
    }

    public function down(): void
    {
        Schema::table('booking_decisions', function (Blueprint $table) {
            $table->dropColumn('history');
        });
    }
};
