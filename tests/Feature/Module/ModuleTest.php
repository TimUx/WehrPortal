<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Cache::forget('modules_active_keys');

    // Give each test a fresh registry instance so registrations don't bleed across tests.
    // Save the original so we can restore it in afterEach — forgetInstance replaces the singleton
    // for the entire worker process and would otherwise corrupt tests that run later in the same worker.
    $this->originalRegistry = app(ModuleRegistry::class);
    $this->app->forgetInstance(ModuleRegistry::class);
    $this->registry = app(ModuleRegistry::class);
});

afterEach(function () {
    $this->app->instance(ModuleRegistry::class, $this->originalRegistry);
    Cache::forget('modules_active_keys');
});

// ---------------------------------------------------------------------------
// ModuleRegistry
// ---------------------------------------------------------------------------

test('registry registers and returns module definitions', function () {
    $this->registry->register('test', 'Test-Modul', 'Ein Testmodul', ['nutzer', 'leiter'], 'App\\Test\\Provider');

    expect($this->registry->has('test'))->toBeTrue();
    expect($this->registry->get('test')['name'])->toBe('Test-Modul');
    expect($this->registry->get('test')['provider'])->toBe('App\\Test\\Provider');
    expect($this->registry->get('test')['roles'])->toBe(['nutzer', 'leiter']);
    expect($this->registry->all())->toHaveKey('test');
});

test('registry returns null for unknown module', function () {
    expect($this->registry->get('unknown'))->toBeNull();
    expect($this->registry->has('unknown'))->toBeFalse();
});

test('isActive returns false when modules table has no row', function () {
    $this->registry->register('test', 'Test', '', []);

    expect($this->registry->isActive('test'))->toBeFalse();
});

test('isActive returns false for an inactive module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => false]);

    expect($this->registry->isActive('test'))->toBeFalse();
});

test('isActive returns true for an active module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    expect($this->registry->isActive('test'))->toBeTrue();
});

test('isActive returns false for an unregistered key even if the DB row exists', function () {
    Module::create(['key' => 'ghost', 'is_active' => true]);

    expect($this->registry->isActive('ghost'))->toBeFalse();
});

test('Module model clears the active-keys cache on save', function () {
    $this->registry->register('test', 'Test', '', []);
    // Warm the cache; real modules from migrations may already be present.
    $before = $this->registry->activeKeys();
    expect(Cache::has('modules_active_keys'))->toBeTrue();
    expect($before)->not->toContain('test');

    Module::create(['key' => 'test', 'is_active' => true]);

    // Cache must have been busted by the model's booted listener.
    expect(Cache::has('modules_active_keys'))->toBeFalse();
    expect($this->registry->isActive('test'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// User::canUseModule
// ---------------------------------------------------------------------------

test('admin can use any active module without explicit grant', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $admin = User::factory()->admin()->create();

    expect($admin->canUseModule('test'))->toBeTrue();
});

test('admin cannot use a deactivated module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => false]);

    $admin = User::factory()->admin()->create();

    expect($admin->canUseModule('test'))->toBeFalse();
});

test('non-admin without grant cannot use active module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $user = User::factory()->create();

    expect($user->canUseModule('test'))->toBeFalse();
});

test('non-admin with grant can use active module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'test', 'role' => null]);

    expect($user->canUseModule('test'))->toBeTrue();
});

test('non-admin with grant cannot use deactivated module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => false]);

    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'test', 'role' => null]);

    expect($user->canUseModule('test'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// User::hasModuleRole
// ---------------------------------------------------------------------------

test('hasModuleRole returns true for the correct role', function () {
    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'test', 'role' => 'leiter']);

    expect($user->hasModuleRole('test', 'leiter'))->toBeTrue();
    expect($user->hasModuleRole('test', 'nutzer'))->toBeFalse();
});

test('hasModuleRole returns false without any grant', function () {
    $user = User::factory()->create();

    expect($user->hasModuleRole('test', 'leiter'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Middleware EnsureModuleAccess
// ---------------------------------------------------------------------------

beforeEach(function () {
    Route::middleware(['web', 'auth', EnsureModuleAccess::class.':test'])
        ->get('/_test_module', fn () => 'ok')
        ->name('_test_module');
});

test('middleware returns 404 when module is deactivated', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => false]);

    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/_test_module')->assertNotFound();
});

test('middleware returns 403 when user has no access', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $user = User::factory()->create(); // non-admin, no grant

    $this->actingAs($user)->get('/_test_module')->assertForbidden();
});

test('middleware allows admin through an active module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/_test_module')->assertOk();
});

test('middleware allows a granted non-admin through an active module', function () {
    $this->registry->register('test', 'Test', '', []);
    Module::create(['key' => 'test', 'is_active' => true]);

    $user = User::factory()->create();
    $user->moduleAccess()->create(['module' => 'test', 'role' => null]);

    $this->actingAs($user)->get('/_test_module')->assertOk();
});
