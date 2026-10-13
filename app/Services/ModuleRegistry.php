<?php

namespace App\Services;

use App\Models\Module;
use App\Models\User;
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
    /**
     * $adminRoute/$canAdmin: a module administered inside the app by a role (not the Filament panel),
     * e.g. the Personalverwalter; the header's "Admin" leads there while inside the module.
     */
    public function register(string $key, string $name, string $description, array $roles = [], ?string $provider = null, string $route = '/', ?Closure $audience = null, ?string $audienceLabel = null, ?string $adminRoute = null, ?Closure $canAdmin = null): void
    {
        $this->definitions[$key] = ['name' => $name, 'description' => $description, 'route' => $route, 'provider' => $provider, 'roles' => $roles, 'audience' => $audience, 'audience_label' => $audienceLabel,
            'admin_route' => $adminRoute, 'can_admin' => $canAdmin];
    }

    /** The in-app admin page of the module the current route belongs to, if the user may open it */
    public function adminRouteFor(?string $routeName, ?User $user): ?string
    {
        if (! $routeName || ! $user) {
            return null;
        }
        foreach ($this->definitions as $key => $def) {
            if ($def['admin_route'] && str_starts_with($routeName, $key.'.') && $def['can_admin'] && ($def['can_admin'])($user)) {
                return $def['admin_route'];
            }
        }

        return null;
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
