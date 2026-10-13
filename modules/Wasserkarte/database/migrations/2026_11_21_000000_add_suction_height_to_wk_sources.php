<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Saughöhe of a Saugstelle (m): how far the pump has to lift the water */
    public function up(): void
    {
        Schema::table('wk_sources', fn (Blueprint $table) => $table->decimal('suction_height_m', 3, 1)->nullable());
    }

    public function down(): void
    {
        Schema::table('wk_sources', fn (Blueprint $table) => $table->dropColumn('suction_height_m'));
    }
};
