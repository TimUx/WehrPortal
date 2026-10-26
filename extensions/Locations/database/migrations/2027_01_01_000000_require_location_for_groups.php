<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultLocationId = DB::table('x_locations')->where('is_default', true)->value('id')
            ?? DB::table('x_locations')->orderBy('sort_order')->orderBy('id')->value('id');

        if ($defaultLocationId === null) {
            throw new RuntimeException('A default Standort is required before assigning Abteilungen.');
        }

        DB::table('groups')->whereNull('ext_location_id')->update(['ext_location_id' => $defaultLocationId]);
        Schema::table('groups', fn (Blueprint $table) => $table->dropForeign(['ext_location_id']));
        DB::statement('ALTER TABLE groups ALTER COLUMN ext_location_id SET NOT NULL');
        Schema::table('groups', fn (Blueprint $table) => $table->foreign('ext_location_id')
            ->references('id')->on('x_locations')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $table) => $table->dropForeign(['ext_location_id']));
        DB::statement('ALTER TABLE groups ALTER COLUMN ext_location_id DROP NOT NULL');
        Schema::table('groups', fn (Blueprint $table) => $table->foreign('ext_location_id')
            ->references('id')->on('x_locations')->nullOnDelete());
    }
};
