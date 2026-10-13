<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kürzel of a Lehrgang (e.g. "TF", "Ma"), shown next to the Kameraden who have it */
    public function up(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->string('short', 12)->nullable());
    }

    public function down(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn('short'));
    }
};
