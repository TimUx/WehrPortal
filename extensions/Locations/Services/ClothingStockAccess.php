<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\StockAccess;

class ClothingStockAccess extends StockAccess
{
    private ?User $actor = null;

    public function enabled(): bool
    {
        return (bool) config('locations.clothing_stock_enabled', false);
    }

    public function validateConfiguration(): void
    {
        if (! $this->enabled()) {
            return;
        }
        if (! config('locations.enabled')) {
            throw new InvalidArgumentException('The clothing stock adapter requires Locations.');
        }
        foreach ([
            'users' => ['is_active', 'is_admin', 'ext_location_id'],
            'modules' => ['key', 'is_active'],
            'module_user' => ['user_id', 'module', 'role'],
            'x_locations' => ['is_active'],
            'x_location_user' => ['location_id', 'user_id'],
            'x_location_module_roles' => ['module', 'role', 'granted_at', 'expires_at', 'revoked_at'],
            'kk_stock' => ['ext_location_id', 'quantity'],
            'kk_issued_items' => ['ext_source_stock_id'],
        ] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new InvalidArgumentException("The clothing stock adapter requires migrated schema: {$table}.");
            }
        }
    }

    public function currentActor(): ?User
    {
        return $this->actor ?? auth()->user();
    }

    public function asActor(?User $actor, Closure $operation): mixed
    {
        $previous = $this->actor;
        $this->actor = $actor;
        try {
            return $operation();
        } finally {
            $this->actor = $previous;
        }
    }

    private function eligible(?User $actor): bool
    {
        return $actor !== null && app(LocationModuleRoles::class)->eligible($actor, 'kleiderkammer');
    }

    public function isGlobal(?User $actor): bool
    {
        return $this->eligible($actor) && app(LocationModuleRoles::class)->isGlobalAdmin($actor);
    }

    /** @return Builder<Stock> */
    public function query(?User $actor): Builder
    {
        $query = Stock::query();
        if (! $this->enabled()) {
            return $query;
        }
        if (! $this->eligible($actor)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->isGlobal($actor)) {
            return $query;
        }

        return $query->whereIn('ext_location_id', app(LocationModuleRoles::class)->capabilityLocationIds($actor, 'kleiderkammer', 'bestand'));
    }

    public function canEnter(?User $actor): bool
    {
        if (! $this->enabled()) {
            return parent::canEnter($actor);
        }

        return $this->isGlobal($actor) || ($this->eligible($actor)
            && app(LocationModuleRoles::class)->capabilityLocationIds($actor, 'kleiderkammer', 'bestand') !== []);
    }

    public function canManageCatalog(?User $actor): bool
    {
        return $this->enabled() ? $this->isGlobal($actor) : parent::canManageCatalog($actor);
    }

    public function canEdit(?User $actor, Stock $stock): bool
    {
        return $this->enabled()
            ? $this->query($actor)->whereKey($stock->getKey())->exists()
            : parent::canEdit($actor, $stock);
    }

    public function canIssue(?User $actor, Stock $stock): bool
    {
        return $this->enabled()
            ? $this->hasPersonalPermission($actor) && $this->canEdit($actor, $stock)
            : parent::canIssue($actor, $stock);
    }

    private function hasPersonalPermission(?User $actor): bool
    {
        if (! $this->eligible($actor)) {
            return false;
        }
        if ($this->isGlobal($actor)) {
            return true;
        }

        return DB::table('module_user')
            ->where('module_user.user_id', $actor->id)->where('module_user.module', 'kleiderkammer')
            ->where('module_user.role', 'kleiderwart')->exists();
    }

    public function authorizePersonal(?User $actor): void
    {
        if ($this->enabled()) {
            abort_unless($this->hasPersonalPermission($actor), 403);
        }
    }

    public function authorizeStock(?User $actor, Stock $stock): void
    {
        if ($this->enabled()) {
            abort_unless($this->canEdit($actor, $stock), 403);
        }
    }

    public function authorizeLegacyReturn(?User $actor): void
    {
        if ($this->enabled()) {
            abort_unless($this->isGlobal($actor), 403);
        }
    }

    public function authorizeTransfer(?User $actor): void
    {
        if ($this->enabled()) {
            abort_unless($this->isGlobal($actor), 403);
        }
    }

    public function guardMutation(Stock $stock, string $event): void
    {
        if (! $this->enabled()) {
            return;
        }
        $actor = $this->currentActor();
        if ($event !== 'updating' || array_diff(array_keys($stock->getDirty()), ['quantity', 'updated_at']) !== []) {
            abort_unless($this->isGlobal($actor), 403);
        } else {
            $this->authorizeStock($actor, $stock);
        }
        if ($event !== 'deleting') {
            validator(['quantity' => $stock->quantity], ['quantity' => ['required', 'integer', 'min:0']])->validate();
        }
    }
}
