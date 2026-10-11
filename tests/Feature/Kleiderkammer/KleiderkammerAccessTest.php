<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

// Activate the kleiderkammer module for tests that need it
function activateKleiderkammer(): void
{
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Cache::forget('module_auto_assign_kleiderkammer');
}

beforeEach(function () {
    Cache::forget('modules_active_keys');
    Cache::forget('module_auto_assign_kleiderkammer');
});

// ---------------------------------------------------------------------------
// Panel access
// ---------------------------------------------------------------------------

test('admin can access kleiderkammer panel when module is active', function () {
    activateKleiderkammer();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/kleiderkammer')
        ->assertOk();
});

test('admin cannot access kleiderkammer panel when module is inactive', function () {
    // Module row is inserted by migration with is_active = false — no update needed
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/kleiderkammer')
        ->assertForbidden();
});

test('user with kleiderwart grant can access kleiderkammer panel', function () {
    activateKleiderkammer();
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    $this->actingAs($user)
        ->get('/admin/kleiderkammer')
        ->assertOk();
});

test('user with grant but module inactive cannot access kleiderkammer panel', function () {
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    $this->actingAs($user)
        ->get('/admin/kleiderkammer')
        ->assertForbidden();
});

test('active user without grant cannot access kleiderkammer panel', function () {
    activateKleiderkammer();
    $user = User::factory()->create(); // active, no grant

    $this->actingAs($user)
        ->get('/admin/kleiderkammer')
        ->assertForbidden();
});

test('inactive user cannot access kleiderkammer panel', function () {
    activateKleiderkammer();
    $user = User::factory()->inactive()->create();
    $user->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);

    $this->actingAs($user)
        ->get('/admin/kleiderkammer')
        ->assertForbidden();
});

test('unauthenticated request is redirected to login', function () {
    $this->get('/admin/kleiderkammer')
        ->assertRedirect();
});

test('kleiderkammer panel shows version and link back to overview', function () {
    activateKleiderkammer();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/kleiderkammer')
        ->assertOk()
        ->assertSee(config('app.version'))
        ->assertSee('Zur Übersicht');
});
