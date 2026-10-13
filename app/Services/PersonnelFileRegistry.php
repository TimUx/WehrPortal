<?php

namespace App\Services;

use App\Models\User;

/** Menu items of the Personalakte that modules add, e.g. "Einsätze" from the Einsatzbericht */
class PersonnelFileRegistry
{
    /** @var array<string, array{module: string, label: string, route: string, visible: ?\Closure}> */
    private array $items = [];

    /** $visible: an extra condition, e.g. only while a setting of the module is on */
    public function register(string $module, string $label, string $route, ?\Closure $visible = null): void
    {
        $this->items[$route] = compact('module', 'label', 'route', 'visible');
    }

    /** @return list<array{label: string, route: string}> the items this Kamerad can open */
    public function for(User $user): array
    {
        return collect($this->items)
            ->filter(fn ($item) => $user->canUseModule($item['module']) && (! $item['visible'] || ($item['visible'])($user)))
            ->map(fn ($item) => ['label' => $item['label'], 'route' => $item['route']])
            ->values()->all();
    }
}
