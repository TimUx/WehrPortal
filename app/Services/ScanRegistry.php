<?php

namespace App\Services;

/**
 * Central registry that maps item codes to destination URLs.
 * Each module registers a resolver in its ServiceProvider::boot().
 * ScanController queries this to handle /i/{code} for all modules.
 */
class ScanRegistry
{
    /** @var array<string, callable(string): ?string> */
    private array $resolvers = [];

    /** @var array<string, callable(string): ?string> */
    private array $fallbacks = [];

    /**
     * Register a resolver for a module.
     *
     * The callable receives the raw code string and returns an absolute URL,
     * or null if the code is not owned by this module.
     */
    public function register(string $module, callable $resolver): void
    {
        $this->resolvers[$module] = $resolver;
    }

    /**
     * Register a resolver that only runs when no module recognised the code, for modules that
     * react to unknown codes (e.g. offering to register a new item). Without this, such a
     * module would swallow the codes of every module registered after it.
     */
    public function registerFallback(string $module, callable $resolver): void
    {
        $this->fallbacks[$module] = $resolver;
    }

    /** Only the modules that own the code, without the fallbacks that offer to register unknown ones */
    public function resolveKnown(string $code): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $url = $resolver($code);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Try every registered resolver in registration order, then the fallbacks.
     * Returns the first non-null URL, or null if no module claims the code.
     */
    public function resolve(string $code): ?string
    {
        foreach ([...array_values($this->resolvers), ...array_values($this->fallbacks)] as $resolver) {
            $url = $resolver($code);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }
}
