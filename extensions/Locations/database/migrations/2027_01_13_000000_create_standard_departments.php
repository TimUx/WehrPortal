<?php

use Extensions\Locations\Services\StandardDepartments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('x_locations')->orderBy('id')->pluck('id') as $locationId) {
            foreach (StandardDepartments::NAMES as $index => $name) {
                $exists = DB::table('groups')->where('ext_location_id', $locationId)->where('name', $name)->exists();
                if ($exists) {
                    continue;
                }

                DB::table('groups')->insert([
                    'name' => $name,
                    'ext_location_id' => $locationId,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void {}
};
