<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds the entries of the Modulleiste (ModuleRegistry::navigation): its tabs.
 */
class ModuleNav
{
    /**
     * A tab. $also are further route names (wildcards allowed) on which it counts as the current one,
     * e.g. the detail pages of a list.
     *
     * @param  list<string>  $also
     */
    public static function tab(string $label, string $route, array $also = [], ?int $badge = null, array $params = []): array
    {
        return ['label' => $label, 'route' => $route, 'also' => $also, 'badge' => $badge ?: null, 'params' => $params, 'active' => false];
    }

    /**
     * Marks the current tab: the one whose route or $also matches, else the first one (a page of the
     * module without its own tab belongs to the start of the module).
     *
     * @param  list<array>  $tabs
     * @return list<array>
     */
    public static function markActive(array $tabs, string $routeName): array
    {
        $match = fn (array $tab) => collect([$tab['route'], ...$tab['also']])->contains(fn ($pattern) => Str::is($pattern, $routeName));
        $index = collect($tabs)->search($match);
        if ($index === false && $tabs) {
            $index = 0;
        }

        return array_map(fn ($tab, $i) => [...$tab, 'active' => $i === $index, 'url' => route($tab['route'], $tab['params'])], $tabs, array_keys($tabs));
    }
}
