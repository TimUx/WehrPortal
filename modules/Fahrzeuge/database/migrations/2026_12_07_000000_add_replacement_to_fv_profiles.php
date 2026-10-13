<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Planned Ersatzbeschaffung of a vehicle, for the Investitionsplanung in the Bestand */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fv_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('replacement_year')->nullable();
            $table->unsignedBigInteger('replacement_cost_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fv_profiles', fn (Blueprint $t) => $t->dropColumn(['replacement_year', 'replacement_cost_cents']));
    }
};
