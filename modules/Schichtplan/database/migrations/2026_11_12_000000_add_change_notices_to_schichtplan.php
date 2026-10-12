<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each Kamerad was last told about a published plan, so later changes by the planer reach
 * the people affected once the planer has stopped editing (pending_* is the debounce).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->json('notified_snapshot')->nullable();
            $table->string('pending_hash', 64)->nullable();
            $table->timestampTz('pending_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->dropColumn(['notified_snapshot', 'pending_hash', 'pending_since']);
        });
    }
};
