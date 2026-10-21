<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Modules\Kleiderkammer\src\Models\Stock;

/**
 * Optional target-aware stock adapter. The default preserves upstream authorization.
 * Personal management remains independent of any stock-only permission.
 */
class StockAccess
{
    public function enabled(): bool
    {
        return false;
    }

    /** @return Builder<Stock> */
    public function query(?User $actor): Builder
    {
        return Stock::query();
    }

    public function canEnter(?User $actor): bool
    {
        return $actor?->canManageKleiderkammer() ?? false;
    }

    public function canManageCatalog(?User $actor): bool
    {
        return $this->canEnter($actor);
    }

    public function canEdit(?User $actor, Stock $stock): bool
    {
        return $this->canEnter($actor);
    }

    public function canIssue(?User $actor, Stock $stock): bool
    {
        return $actor?->canManageKleiderkammer() ?? false;
    }

    public function authorizePersonal(?User $actor): void {}

    public function authorizeStock(?User $actor, Stock $stock): void {}

    public function authorizeLegacyReturn(?User $actor): void {}

    public function authorizeTransfer(?User $actor): void {}

    public function asActor(?User $actor, Closure $operation): mixed
    {
        return $operation();
    }
}
