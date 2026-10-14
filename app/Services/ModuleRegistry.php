<?php

namespace App\Services;

use App\Models\Module;
use App\Models\User;
use App\Support\ModuleNav;
use Closure;
use Illuminate\Support\Facades\Cache;

class ModuleRegistry
{
    /** @var array<string, array{name: string, description: string, route: string, provider: ?string, roles: string[]}> */
    private array $definitions = [];

    /**
     * Register a module.
     *
     * @param  string[]  $roles  Possible roles within this module (empty = no role distinction)
     * @param  ?string  $provider  The module's service provider class
     * @param  string  $route  Named route or absolute path for the module's dashboard card
     */
    /**
     * @param  ?Closure(User): bool  $audience  for whom the module is meant (e.g. only the Vorstand); giving it to
     *                                          someone else needs a confirmation, and "automatisch freischalten" covers only them
     * @param  ?string  $audienceLabel  that group in the text, e.g. "Vorstand"
     */
    public function register(string $key, string $name, string $description, array $roles = [], ?string $provider = null, string $route = '/', ?Closure $audience = null, ?string $audienceLabel = null): void
    {
        $this->definitions[$key] = ['name' => $name, 'description' => $description, 'route' => $route, 'provider' => $provider, 'roles' => $roles, 'audience' => $audience, 'audience_label' => $audienceLabel];
    }

    /** @var array<string, Closure> */
    private array $navigations = [];

    /**
     * The Modulleiste of a module: its tabs, one main action and the link to its Verwaltung, each
     * only for who may use it. The closure gets the user and returns
     * ['tabs' => list<?array>, 'action' => ?array, 'manage' => ?string url]; build tabs and the action
     * with App\Support\ModuleNav, null entries are dropped.
     *
     * @param  Closure(User): array{tabs?: list<?array>, action?: ?array, manage?: ?string}  $items
     */
    public function navigation(string $key, Closure $items): void
    {
        $this->navigations[$key] = $items;
    }

    /**
     * The Modulleiste for the current page: the module whose routes are named "<key>.*". Pages of the
     * Personalakte (/personalakte/…) belong to the Personalakte, not to the module that adds them.
     *
     * @return ?array{key: string, name: string, url: string, tabs: list<array>, action: ?array, manage: ?string}
     */
    public function navigationFor(?string $routeName, ?User $user): ?array
    {
        if (! $routeName || ! $user || ! ($key = strstr($routeName, '.', true)) || ! isset($this->navigations[$key]) || ! $this->isActive($key) || ! $user->canUseModule($key)) {
            return null;
        }
        $nav = ($this->navigations[$key])($user);
        $tabs = array_values(array_filter($nav['tabs'] ?? []));
        $def = $this->definitions[$key];

        return ['key' => $key, 'name' => $def['name'], 'url' => $tabs ? route($tabs[0]['route'], $tabs[0]['params']) : (str_starts_with($def['route'], '/') ? url($def['route']) : route($def['route'])),
            'tabs' => ModuleNav::markActive($tabs, $routeName), 'action' => $nav['action'] ?? null, 'manage' => $nav['manage'] ?? null];
    }

    /** Whether the module is meant for this user: true for modules without a restricted audience and for admins */
    public function isMeantFor(string $key, User $user): bool
    {
        $audience = $this->definitions[$key]['audience'] ?? null;

        return $audience === null || $user->is_admin || $audience($user);
    }

    public function isRestricted(string $key): bool
    {
        return ($this->definitions[$key]['audience'] ?? null) !== null;
    }

    /** All registered module definitions, keyed by module key. */
    public function all(): array
    {
        return $this->definitions;
    }

    /** Definition for one module, or null if not registered. */
    public function get(string $key): ?array
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * Whether a registered module is currently active.
     * Returns false if the module is not registered.
     * Returns false gracefully if the modules table does not exist yet (fresh install).
     */
    public function isActive(string $key): bool
    {
        return $this->has($key) && in_array($key, $this->activeKeys());
    }

    /**
     * Keys of all currently active modules, cached for 60 seconds.
     * Returns an empty array if the modules table does not exist yet.
     */
    public function activeKeys(): array
    {
        return Cache::remember('modules_active_keys', 60, function () {
            try {
                return Module::where('is_active', true)->pluck('key')->all();
            } catch (\Exception) {
                return [];
            }
        });
    }

    public function clearCache(): void
    {
        Cache::forget('modules_active_keys');
    }
}
