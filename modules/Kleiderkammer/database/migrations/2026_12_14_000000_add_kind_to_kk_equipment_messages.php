<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** defect = Mangel gemeldet (as before), washing = Wäsche angefordert (body is the reason) */
    public function up(): void
    {
        Schema::table('kk_equipment_messages', fn (Blueprint $table) => $table->string('kind', 20)->default('defect'));
    }

    public function down(): void
    {
        Schema::table('kk_equipment_messages', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
