<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lehrgänge that need a refresher (e.g. every 24 months): the interval, and which due date was reminded */
    public function up(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->unsignedSmallInteger('repeat_months')->nullable());
        Schema::table('lq_records', fn (Blueprint $table) => $table->date('refresh_reminded_on')->nullable());
    }

    public function down(): void
    {
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropColumn('refresh_reminded_on'));
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn('repeat_months'));
    }
};
