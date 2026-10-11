<?php

namespace App\Services;

use App\Models\User;

/**
 * Cards that modules put on the personal start page (e.g. "Nächster Termin"). A card is shown only
 * while its module is active and the Kamerad may use it; the callable returns a view or null.
 */
class DashboardRegistry
{
    /** Full width above everything else (e.g. notices) */
    public const TOP = 'top';

    /** Small cards side by side (e.g. next event) */
    public const CARDS = 'cards';

    /** @var array<string, array{module: string, position: string, render: callable(User): mixed}> */
    private array $cards = [];

    public function register(string $module, string $name, callable $render, string $position = self::CARDS): void
    {
        $this->cards[$module.'.'.$name] = ['module' => $module, 'position' => $position, 'render' => $render];
    }

    /** @return array<int, mixed> rendered cards for this Kamerad, in registration order */
    public function for(User $user, string $position = self::CARDS): array
    {
        $registry = app(ModuleRegistry::class);

        return collect($this->cards)
            ->filter(fn ($card) => $card['position'] === $position)
            ->filter(fn ($card) => $registry->isActive($card['module']) && $user->canUseModule($card['module']))
            ->map(fn ($card) => ($card['render'])($user))
            ->filter()
            ->values()
            ->all();
    }
}
