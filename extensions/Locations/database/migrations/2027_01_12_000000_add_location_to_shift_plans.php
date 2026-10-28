<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sp_plans') || ! Schema::hasTable('x_locations')) {
            return;
        }

        if (! Schema::hasColumn('sp_plans', 'ext_location_id')) {
            Schema::table('sp_plans', function (Blueprint $table): void {
                $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
            });
        }

        $defaultLocationId = DB::table('x_locations')->where('is_active', true)->where('is_default', true)->value('id')
            ?? DB::table('x_locations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->value('id');

        foreach (DB::table('sp_plans')->whereNull('ext_location_id')->orderBy('id')->get() as $plan) {
            $audienceGroupIds = json_decode((string) $plan->audience_groups, true);
            $audienceGroupIds = is_array($audienceGroupIds)
                ? array_values(array_unique(array_filter(array_map('intval', $audienceGroupIds))))
                : [];
            $groupIds = array_values(array_unique(array_filter([
                $plan->group_id,
                ...$audienceGroupIds,
            ])));
            $groupLocations = $groupIds === []
                ? collect()
                : DB::table('groups')->join('x_locations', 'x_locations.id', '=', 'groups.ext_location_id')
                    ->whereIn('groups.id', $groupIds)->where('x_locations.is_active', true)->whereNotNull('groups.ext_location_id')
                    ->pluck('ext_location_id')->map(fn ($id) => (int) $id)->unique()->values();

            $locationId = $plan->group_id
                ? DB::table('groups')->join('x_locations', 'x_locations.id', '=', 'groups.ext_location_id')
                    ->where('groups.id', $plan->group_id)->where('x_locations.is_active', true)->value('groups.ext_location_id')
                : null;
            if ($locationId === null && $groupLocations->count() === 1) {
                $locationId = $groupLocations->first();
            }
            if ($locationId === null && $plan->created_by) {
                $locationId = DB::table('users')->join('x_locations', 'x_locations.id', '=', 'users.ext_location_id')
                    ->where('users.id', $plan->created_by)->where('x_locations.is_active', true)->value('users.ext_location_id');
            }
            $locationId = $locationId ?? $defaultLocationId;

            if ($locationId === null) {
                throw new RuntimeException('Cannot assign an existing shift plan without an active location.');
            }

            DB::table('sp_plans')->where('id', $plan->id)->update(['ext_location_id' => $locationId]);

            if ($audienceGroupIds !== []) {
                $validGroups = DB::table('groups')->whereIn('id', $audienceGroupIds)
                    ->where('ext_location_id', $locationId)->pluck('id')->map(fn ($id) => (int) $id)->all();
                DB::table('sp_plans')->where('id', $plan->id)->update([
                    'audience_groups' => $validGroups === [] ? null : json_encode($validGroups),
                ]);
            }
            if ($plan->group_id && ! DB::table('groups')->where('id', $plan->group_id)->where('ext_location_id', $locationId)->exists()) {
                DB::table('sp_plans')->where('id', $plan->id)->update(['group_id' => null]);
            }
        }

        DB::statement('ALTER TABLE sp_plans ALTER COLUMN ext_location_id SET NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasColumn('sp_plans', 'ext_location_id')) {
            Schema::table('sp_plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('ext_location_id'));
        }
    }
};
