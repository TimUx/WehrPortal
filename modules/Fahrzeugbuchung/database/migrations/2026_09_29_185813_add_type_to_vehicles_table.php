<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('type')->default('truck')->after('short_name');
        });

        // The trailer from the base data (fresh installs; existing databases already have it)
        DB::table('vehicles')->where('short_name', 'JF-Anh')->update(['type' => 'trailer']);
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
