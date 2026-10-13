<?php

namespace App\Services;

/**
 * Qualifikationen as short codes next to a Kamerad (Personalverwaltung list): each module tells what is
 * valid today – e.g. AGT and CSA (Atemschutz), C1 (Führerscheine), Lehrgänge and Unterweisungen with a Kürzel.
 */
class BadgeRegistry
{
    /** @var array<string, callable> */
    private array $providers = [];

    /** @param  callable(list<int> $userIds): array<int, list<string>>  $badges  user id → codes */
    public function register(string $module, callable $badges): void
    {
        $this->providers[$module] = $badges;
    }

    /** @return array<int, list<string>> */
    public function for(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $registry = app(ModuleRegistry::class);
        $all = [];
        foreach ($this->providers as $module => $badges) {
            if (! $registry->isActive($module)) {
                continue;
            }
            foreach ($badges($userIds) as $userId => $codes) {
                $all[$userId] = array_values(array_unique([...($all[$userId] ?? []), ...$codes]));
            }
        }

        return $all;
    }
}
