<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The start page sorts the modules by use (a score that halves every 30 days); a Kamerad can lock
 * the own order instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_module_preferences', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->nullable()->default(null)->change();
            $table->double('usage_score')->default(0);
            $table->timestamp('used_at')->nullable();
        });
        Schema::table('users', fn (Blueprint $table) => $table->boolean('modules_locked')->default(false));

    }

    public function down(): void
    {
        DB::table('user_module_preferences')->whereNull('sort_order')->update(['sort_order' => 1000]);
        Schema::table('user_module_preferences', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->nullable(false)->change();
            $table->dropColumn(['usage_score', 'used_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('modules_locked'));
    }
};
