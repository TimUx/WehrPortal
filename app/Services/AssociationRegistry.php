<?php

namespace App\Services;

use App\Models\User;
use Closure;

class AssociationRegistry
{
    private ?Closure $isMember = null;

    private ?Closure $setMembership = null;

    public function register(callable $isMember, callable $setMembership): void
    {
        $this->isMember = Closure::fromCallable($isMember);
        $this->setMembership = Closure::fromCallable($setMembership);
    }

    public function supportsMembership(): bool
    {
        return $this->isMember !== null && $this->setMembership !== null;
    }

    public function isMember(User $user): bool
    {
        return $this->isMember ? (bool) ($this->isMember)($user) : false;
    }

    public function setMembership(User $user, bool $isMember): void
    {
        if ($this->setMembership) {
            ($this->setMembership)($user, $isMember);
        }
    }
}
