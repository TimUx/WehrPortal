<?php

namespace App\Filament;

use App\Models\User;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The picture in the admin panel's user menu: the Kamerad's own avatar, otherwise the initials
 * drawn here. Filament's default asks ui-avatars.com, which would tell an outside service the
 * initials and the address of everyone opening the panel.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        if ($record instanceof User && $record->avatarUrl() !== null) {
            return $record->avatarUrl();
        }

        $initials = $record instanceof User ? $record->initials() : mb_strtoupper(mb_substr(trim(Filament::getNameForDefaultAvatar($record)), 0, 1));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#1f2937"/>'
            .'<text x="32" y="33" fill="#ffffff" font-family="system-ui,sans-serif" font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="central">'
            .htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
