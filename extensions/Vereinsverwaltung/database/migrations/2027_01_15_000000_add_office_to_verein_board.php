<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The Amt of a board member (Vorsitz, Kasse, Schriftführung, Beisitz) and whether it is a Stellvertretung */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_vv_association_user', function (Blueprint $table) {
            $table->string('office', 20)->nullable();
            $table->boolean('is_deputy')->default(false);
        });

        DB::statement("ALTER TABLE x_vv_association_user ADD CONSTRAINT x_vv_association_user_office CHECK (office IS NULL OR office IN ('vorsitz', 'kasse', 'schrift', 'beisitz'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE x_vv_association_user DROP CONSTRAINT IF EXISTS x_vv_association_user_office');
        Schema::table('x_vv_association_user', function (Blueprint $table) {
            $table->dropColumn(['office', 'is_deputy']);
        });
    }
};
