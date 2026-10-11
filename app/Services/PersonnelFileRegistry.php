<?php

namespace App\Services;

use App\Models\User;

/** Menu items of the Personalakte that modules add, e.g. "Einsätze" from the Einsatzbericht */
class PersonnelFileRegistry
{
    /** @var array<string, array{module: string, label: string, route: string}> */
    private array $items = [];

    public function register(string $module, string $label, string $route): void
    {
        $this->items[$route] = compact('module', 'label', 'route');
    }

    /** @return list<array{label: string, route: string}> the items this Kamerad can open */
    public function for(User $user): array
    {
        return collect($this->items)
            ->filter(fn ($item) => $user->canUseModule($item['module']))
            ->map(fn ($item) => ['label' => $item['label'], 'route' => $item['route']])
            ->values()->all();
    }
}
