<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Application tables with an optional Standort. */
    private const TABLES = [
        'users', 'groups', 'member_functions', 'vehicles', 'ar_equipment', 'ar_categories',
        'kk_items', 'eb_reports', 'mm_defects', 'kal_events', 'x_vv_members',
    ];

    public function up(): void
    {
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

        Schema::create('x_location_user', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained('x_locations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // member | admin
            $table->primary(['location_id', 'user_id']);
        });

        // At least one Standort always exists
        DB::table('x_locations')->insert([
            'name' => 'Hauptstandort', 'slug' => 'default', 'is_default' => true,
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->nullOnDelete();
            });
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
