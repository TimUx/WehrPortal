<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Absolvierte Lehrgänge the Ausbildungsleiter enters afterwards (before the app, outside the Kreis) */
    public function up(): void
    {
        Schema::table('lq_records', fn (Blueprint $table) => $table->boolean('entered_manually')->default(false));
    }

    public function down(): void
    {
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropColumn('entered_manually'));
    }
};
