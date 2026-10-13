<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** CSA-Träger (Chemikalienschutzanzug) and Tragezeiten per Nachweis */
    public function up(): void
    {
        Schema::table('as_wearers', function (Blueprint $table) {
            $table->boolean('is_csa')->default(false);
            $table->date('csa_qualified_on')->nullable();
        });
        Schema::table('as_trainings', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutes')->nullable();   // Tragezeit
            $table->boolean('csa')->default(false);                // worn with Chemikalienschutzanzug
        });
        Schema::table('as_teams', fn (Blueprint $table) => $table->boolean('csa')->default(false));
    }

    public function down(): void
    {
        Schema::table('as_teams', fn (Blueprint $table) => $table->dropColumn('csa'));
        Schema::table('as_trainings', fn (Blueprint $table) => $table->dropColumn(['minutes', 'csa']));
        Schema::table('as_wearers', fn (Blueprint $table) => $table->dropColumn(['is_csa', 'csa_qualified_on']));
    }
};
