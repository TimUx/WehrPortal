<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_hc_hydrants', function (Blueprint $table) {
            $table->string('city')->nullable()->after('ext_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('x_hc_hydrants', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};
