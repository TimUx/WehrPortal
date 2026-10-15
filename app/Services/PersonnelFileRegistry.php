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

    /** @var array<string, array{module: string, title: ?string, component: string, visible: ?\Closure}> */
    private array $sections = [];

    /**
     * A section right on the Profil page instead of an own tab, e.g. the Einsätze or the own keys:
     * a Livewire component, under an optional heading.
     */
    public function section(string $module, ?string $title, string $component, ?\Closure $visible = null): void
    {
        $this->sections[$component] = compact('module', 'title', 'component', 'visible');
    }

    /** @return list<array{title: ?string, component: string, anchor: string}> */
    public function sectionsFor(User $user): array
    {
        return collect($this->sections)
            ->filter(fn ($s) => $user->canUseModule($s['module']) && (! $s['visible'] || ($s['visible'])($user)))
            ->map(fn ($s) => ['title' => $s['title'], 'component' => $s['component'], 'anchor' => $s['module']])
            ->values()->all();
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
