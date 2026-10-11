<?php

namespace App\Support;

use Filament\Support\Colors\Color;

final class BrandColor
{
    public const RED = '#ED2F12';

    public const RED_DARK = '#9B0103';

    /** Filament palette whose 500/600 shades are exactly the brand red (Color::hex() would shift it). */
    public static function palette(): array
    {
        return array_replace(Color::Red, [
            500 => Color::convertToOklch(self::RED),
            600 => Color::convertToOklch(self::RED),
            700 => Color::convertToOklch(self::RED_DARK),
        ]);
    }
}
