<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_locations', function (Blueprint $table) {
            $table->text('crest_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('x_locations', fn (Blueprint $table) => $table->dropColumn('crest_data'));
    }
};
