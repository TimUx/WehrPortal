<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

// Before the first Einsatz every page must open (an empty list once gave a 500)
test('all pages open while there are no reports yet', function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $admin = User::factory()->admin()->create();

    foreach (['/einsatzbericht', '/einsatzbericht/statistik', '/einsatzbericht/kameraden', '/einsatzbericht/einstellungen', '/personalakte/einsaetze', '/personalakte'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});
