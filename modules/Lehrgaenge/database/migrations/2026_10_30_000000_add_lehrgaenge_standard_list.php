<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Standardliste: Lehrgänge that stay offered even while fox112 doesn't list them (e.g. while the
 * Kreis reworks them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->boolean('is_standard')->default(false));
    }

    public function down(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn('is_standard'));
    }
};
