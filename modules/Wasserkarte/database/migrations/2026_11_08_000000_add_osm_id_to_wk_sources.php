<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sources taken over from OpenStreetMap keep their node id, so a second import doesn't double them */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wk_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('osm_id')->nullable()->unique()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('wk_sources', function (Blueprint $table) {
            $table->dropColumn('osm_id');
        });
    }
};
