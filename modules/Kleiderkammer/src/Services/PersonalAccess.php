<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\Stock;

/** Optional personal-data adapter. The default preserves upstream behavior. */
class PersonalAccess
{
    public function enabled(): bool
    {
        return false;
    }

    public function canEnter(?User $actor): bool
    {
        return $actor?->canManageKleiderkammer() ?? false;
    }

    /** @return Builder<User> */
    public function members(?User $actor): Builder
    {
        return User::query();
    }

    public function authorizeMember(?User $actor, User $member): void {}

    public function authorizeItem(?User $actor, Item $item): void {}

    public function scopeInventory(Builder $query, ?User $actor): void {}

    public function authorizeIssue(?User $actor, User $member, Item|Stock $source): void {}

    public function authorizeIssuance(?User $actor, IssuedItem $issued): void {}

    public function authorizeSelf(?User $actor, User $member): void {}

    public function authorizeTransfer(?User $actor): void {}

    public function authorizeOverview(?User $actor): void {}

    public function canEditItem(?User $actor, Item $item): bool
    {
        return $this->canEnter($actor);
    }

    public function canManageIssuance(?User $actor, IssuedItem $issued): bool
    {
        return $this->canEnter($actor);
    }

    public function asActor(?User $actor, Closure $operation): mixed
    {
        return $operation();
    }

    public function asImportActor(?User $actor, Closure $operation): mixed
    {
        return $this->asActor($actor, $operation);
    }

    public function usesSourceSelector(): bool
    {
        return $this->enabled() || app(StockAccess::class)->enabled();
    }

    /** @return Builder<Stock> */
    public function issueStockQuery(?User $actor): Builder
    {
        return app(StockAccess::class)->query($actor);
    }

    public function canManageCatalog(?User $actor): bool
    {
        return $this->canEnter($actor);
    }

    public function usesMinimalMemberProfile(?User $actor): bool
    {
        return false;
    }
}
