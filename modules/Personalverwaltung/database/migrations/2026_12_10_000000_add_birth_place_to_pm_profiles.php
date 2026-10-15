<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Geburtsort for the Dienstausweis, like on the official card */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_profiles', function (Blueprint $table) {
            $table->string('birth_place', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pm_profiles', fn (Blueprint $t) => $t->dropColumn('birth_place'));
    }
};
