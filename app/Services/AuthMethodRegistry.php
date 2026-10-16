<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;

/**
 * Extension point for login methods. Without a registered method nothing changes: the magic link
 * stays the only way to log in. An enabled exclusive method switches the magic link off for
 * everyone except local admins, who can always log in locally if the identity provider fails.
 */
#[Singleton]
class AuthMethodRegistry
{
    /** @var array<string, AuthMethod> */
    private array $methods = [];

    private ?Closure $localAdminResolver = null;

    public function register(AuthMethod $method): void
    {
        $this->methods[$method->key()] = $method;
    }

    /** @return list<AuthMethod> */
    public function enabled(): array
    {
        return array_values(array_filter($this->methods, fn (AuthMethod $method) => $method->isEnabled()));
    }

    /** True while an enabled method replaces the magic link for normal users */
    public function magicLinkRestricted(): bool
    {
        foreach ($this->enabled() as $method) {
            if ($method->isExclusive()) {
                return true;
            }
        }

        return false;
    }

    /** Lets an extension narrow down which admins count as local admins (default: every active admin) */
    public function resolveLocalAdminUsing(?Closure $resolver): void
    {
        $this->localAdminResolver = $resolver;
    }

    public function isLocalAdmin(User $user): bool
    {
        return $user->is_admin && $user->is_active
            && ($this->localAdminResolver === null || (bool) ($this->localAdminResolver)($user));
    }

    public function allowsMagicLink(User $user): bool
    {
        return ! $this->magicLinkRestricted() || $this->isLocalAdmin($user);
    }
}
