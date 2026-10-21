<?php

namespace Extensions\Locations\Services;

use Illuminate\Support\Facades\DB;

/** Read-only candidates, never an authorization source or an automatic migration. */
class LegacyModuleRoleInventory
{
    public function rows(): array
    {
        return DB::table('module_user')->join('users', 'users.id', '=', 'module_user.user_id')
            ->select('module_user.user_id', 'module_user.module', 'module_user.role', 'users.ext_location_id')
            ->orderBy('module_user.user_id')->orderBy('module_user.module')->get()
            ->map(function ($row): array {
                $ids = DB::table('x_location_user')->where('user_id', $row->user_id)->pluck('location_id');
                if ($row->ext_location_id !== null) {
                    $ids->push($row->ext_location_id);
                }
                $ids = $ids->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

                return ['user_id' => $row->user_id, 'module' => $row->module, 'role' => $row->role,
                    'classification' => match (count($ids)) {
                        0 => 'zero', 1 => 'single', default => 'multiple'
                    },
                    'candidate_location_ids' => $ids];
            })->all();
    }
}
