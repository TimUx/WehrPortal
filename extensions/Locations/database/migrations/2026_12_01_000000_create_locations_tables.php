<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Upstream tables that get a nullable ext_location_id */
    private const TABLES = [
        'users', 'groups', 'member_functions', 'vehicles', 'ar_equipment', 'ar_categories',
        'kk_items', 'eb_reports', 'mm_defects', 'kal_events',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('x_locations')) {
            Schema::create('x_locations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('short_name', 50)->nullable();
                $table->string('slug')->unique();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('x_location_user')) {
            Schema::create('x_location_user', function (Blueprint $table) {
                $table->foreignId('location_id')->constrained('x_locations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 20)->default('member'); // member | admin
                $table->primary(['location_id', 'user_id']);
            });
        }

        // At least one Standort always exists
        if (! DB::table('x_locations')->exists()) {
            DB::table('x_locations')->insert([
                'name' => 'Hauptstandort', 'slug' => 'default', 'is_default' => true,
                'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $default = DB::table('x_locations')->where('is_default', true)->value('id')
            ?? DB::table('x_locations')->orderBy('id')->value('id');

        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            if (! Schema::hasColumn($name, 'ext_location_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->nullOnDelete();
                });
            }

            DB::table($name)->whereNull('ext_location_id')->update(['ext_location_id' => $default]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'ext_location_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('ext_location_id'));
            }
        }

        Schema::dropIfExists('x_location_user');
        Schema::dropIfExists('x_locations');
    }
};
