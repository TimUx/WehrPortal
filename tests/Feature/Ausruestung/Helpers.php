<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;

function activateAusruestung(): void
{
    Module::where('key', 'ausruestung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
}

function ausruestungMember(?string $role = null, array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['phone' => null]);
    $user->moduleAccess()->create(['module' => 'ausruestung', 'role' => $role]);

    return $user;
}

function equipment(array $attributes = []): Equipment
{
    return Equipment::create($attributes + [
        'name' => 'Feuerwehrhelm HPS 7000',
        'category_id' => Category::where('name', 'Feuerwehrhelm')->value('id'),
        'manufacturer' => 'Dräger',
        'model' => 'HPS 7000',
        'serial_number' => '1500',
        'manufactured_at' => today()->subYears(2),
        'next_inspection_at' => today()->addMonths(6),
    ]);
}
