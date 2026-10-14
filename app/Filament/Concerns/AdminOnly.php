<?php

namespace App\Filament\Concerns;

// Core resources and pages of /admin: the panel is also open to module managers (Kleiderwarte …),
// who must only see their module's area.
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin && parent::canAccess();
    }
}
