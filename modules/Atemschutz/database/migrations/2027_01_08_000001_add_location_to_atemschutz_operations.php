<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('as_operations') || Schema::hasColumn('as_operations', 'ext_location_id')) {
            return;
        }

        Schema::table('as_operations', function (Blueprint $table) {
            $table->unsignedBigInteger('ext_location_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('as_operations') && Schema::hasColumn('as_operations', 'ext_location_id')) {
            Schema::table('as_operations', fn (Blueprint $table) => $table->dropColumn('ext_location_id'));
        }
    }
};
