<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gemeinsame Übungen: who else takes part, e.g. neighbouring Wehren or the THW */
    public function up(): void
    {
        Schema::table('kal_events', fn (Blueprint $table) => $table->string('partners', 300)->nullable());
    }

    public function down(): void
    {
        Schema::table('kal_events', fn (Blueprint $table) => $table->dropColumn('partners'));
    }
};
