<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Models\Stock;

/**
 * Optional target-aware stock adapter. The default uses module authorization.
 * Personal management remains independent of any stock-only permission.
 */
class StockAccess
{
    /** Prevent concurrent inserts from turning a unique legacy selection into an arbitrary one. */
    public function lockSelection(): void
    {
        DB::statement('LOCK TABLE kk_stock IN SHARE ROW EXCLUSIVE MODE');
    }

    public function locationFields(): array
    {
        return [];
    }

    public function locationLabel(Stock $stock): ?string
    {
        return 'Bestandsposition #'.$stock->id;
    }

    public function createStock(?User $actor, array $data): Stock
    {
        abort_unless($this->canManageCatalog($actor), 403);

        return Stock::create($data);
    }

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
