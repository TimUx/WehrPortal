<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mm_devices') || Schema::hasColumn('mm_devices', 'ext_location_id')) {
            return;
        }

        Schema::table('mm_devices', function (Blueprint $table) {
            $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('mm_devices') && Schema::hasColumn('mm_devices', 'ext_location_id')) {
            Schema::table('mm_devices', fn (Blueprint $table) => $table->dropConstrainedForeignId('ext_location_id'));
        }
    }
};
