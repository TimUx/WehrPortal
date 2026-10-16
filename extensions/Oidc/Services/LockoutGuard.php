<?php

namespace Extensions\Oidc\Services;

use App\Models\User;
use App\Services\AuthMethodRegistry;
use Illuminate\Validation\ValidationException;

/**
 * While the magic link is switched off for normal users (IdP-only), the last local system admin
 * must not be deleted, deactivated or lose the admin/local flag: nobody could log in if the IdP failed.
 */
class LockoutGuard
{
    /** Active local admins with a usable e-mail address, optionally without one user */
    public static function localAdmins(?int $exceptId = null)
    {
        return User::query()
            ->where('is_admin', true)->where('is_active', true)->where('ext_local_admin', true)
            ->where('email', 'like', '%@%')
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId));
    }

    public static function check(User $user, bool $deleting = false): void
    {
        if (! app(AuthMethodRegistry::class)->magicLinkRestricted()) {
            return;
        }

        $wasLocal = $user->getOriginal('is_admin') && $user->getOriginal('is_active') && $user->getOriginal('ext_local_admin');
        $stillLocal = ! $deleting && $user->is_admin && $user->is_active && $user->ext_local_admin;

        if ($wasLocal && ! $stillLocal && ! self::localAdmins($user->id)->exists()) {
            throw ValidationException::withMessages(['is_admin' => __('oidc::oidc.lockout')]);
        }
    }
}
