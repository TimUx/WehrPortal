<?php

namespace App\Filament\Concerns;

use App\Models\User;

// For a ModuleArea page: open to active users the page allows()
trait IsModuleArea
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && static::allows($user);
    }
}
