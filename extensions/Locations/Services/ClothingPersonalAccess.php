<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\PersonalAccess;
use Modules\Kleiderkammer\src\Services\StockAccess;

class ClothingPersonalAccess extends PersonalAccess
{
    private ?User $actor = null;

    private bool $importing = false;

    public function usesMinimalMemberProfile(?User $actor): bool
    {
        return $this->enabled() && ! $this->isGlobal($actor);
    }

    public function enabled(): bool
    {
        return (bool) config('locations.clothing_personal_enabled', false);
    }

    public function validateConfiguration(): void
    {
        if (! $this->enabled()) {
            return;
        }
        if (! config('locations.enabled')) {
            throw new InvalidArgumentException('The clothing personal adapter requires Locations.');
        }
        foreach ([
            'users' => ['is_active', 'is_admin', 'ext_location_id'],
            'modules' => ['key', 'is_active'],
            'x_locations' => ['is_active'],
            'x_location_user' => ['location_id', 'user_id'],
            'x_location_module_roles' => ['location_id', 'user_id', 'module', 'role', 'granted_at', 'expires_at', 'revoked_at'],
            'kk_items' => ['ext_location_id'],
            'kk_stock' => ['ext_location_id'],
            'kk_issued_items' => ['ext_source_stock_id'],
            'kk_member_sizes' => ['user_id', 'item_type_id', 'size_id'],
            'kk_equipment_messages' => ['user_id', 'issued_item_id', 'handled_at'],
            'kk_item_treatments' => ['item_id'],
        ] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new InvalidArgumentException("The clothing personal adapter requires migrated schema: {$table}.");
            }
        }
    }

    public function currentActor(): ?User
    {
        return $this->actor ?? auth()->user();
    }

    public function hasActorContext(): bool
    {
        return $this->actor !== null;
    }

    public function isImporting(): bool
    {
        return $this->enabled() && $this->importing;
    }

    public function asImportActor(?User $actor, Closure $operation): mixed
    {
        $previous = $this->importing;
        $this->importing = true;
        try {
            return $this->asActor($actor, $operation);
        } finally {
            $this->importing = $previous;
        }
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

    public function locationIds(?User $actor): array
    {
        return $this->eligible($actor)
            ? app(LocationModuleRoles::class)->capabilityLocationIds($actor, 'kleiderkammer', 'kleiderwart')
            : [];
    }

    public function canEnter(?User $actor): bool
    {
        return $this->enabled() ? $this->isGlobal($actor) || $this->locationIds($actor) !== [] : parent::canEnter($actor);
    }

    public function canManageCatalog(?User $actor): bool
    {
        return $this->enabled() ? $this->isGlobal($actor) : parent::canManageCatalog($actor);
    }

    /** @return Builder<User> */
    public function members(?User $actor): Builder
    {
        if (! $this->enabled()) {
            return parent::members($actor);
        }
        $query = User::withoutGlobalScopes();
        if (! $this->eligible($actor)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->isGlobal($actor)) {
            return $query;
        }
        $ids = $this->locationIds($actor);

        // Sizes are one shared personal profile, not fictitious historical location data.
        return $query->where(fn (Builder $q) => $q->whereIn('ext_location_id', $ids)
            ->orWhereIn('id', DB::table('x_location_user')->whereIn('location_id', $ids)->select('user_id')));
    }

    public function authorizeMember(?User $actor, User $member): void
    {
        if ($this->enabled()) {
            abort_unless($this->members($actor)->whereKey($member->id)->exists(), 403);
        }
    }

    public function authorizeSelf(?User $actor, User $member): void
    {
        if ($this->enabled()) {
            abort_unless($this->eligible($actor) && $actor->id === $member->id, 403);
        }
    }

    public function authorizeTransfer(?User $actor): void
    {
        if ($this->enabled()) {
            abort_unless($this->isGlobal($actor), 403);
        }
    }

    private function sourceAllowed(?User $actor, string $table, int $id): bool
    {
        return $this->isGlobal($actor) || DB::table($table)->where('id', $id)
            ->whereIn('ext_location_id', $this->locationIds($actor))->exists();
    }

    public function authorizeItem(?User $actor, Item $item): void
    {
        if ($this->enabled()) {
            abort_unless($this->eligible($actor) && $this->sourceAllowed($actor, 'kk_items', $item->id), 403);
        }
    }

    public function canEditItem(?User $actor, Item $item): bool
    {
        return $this->enabled()
            ? $this->eligible($actor) && $this->sourceAllowed($actor, 'kk_items', $item->id)
            : parent::canEditItem($actor, $item);
    }

    public function authorizeOverview(?User $actor): void
    {
        if ($this->enabled()) {
            abort_unless($this->canEnter($actor), 403);
        }
    }

    public function authorizeIssue(?User $actor, User $member, Item|Stock $source): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->authorizeMember($actor, $member);
        abort_unless($this->sourceAllowed($actor, $source->getTable(), $source->id), 403);
        $location = DB::table($source->getTable())->where('id', $source->id)->value('ext_location_id');
        if (! $this->isGlobal($actor)) {
            abort_unless($location !== null && User::withoutGlobalScopes()->whereKey($member->id)
                ->where(fn (Builder $query) => $query->where('ext_location_id', $location)
                    ->orWhereIn('id', DB::table('x_location_user')->where('location_id', $location)->select('user_id')))
                ->exists(), 403);
        }
        if (app(StockAccess::class)->enabled()) {
            abort_unless($this->isGlobal($actor) || in_array((int) $location,
                app(LocationModuleRoles::class)->capabilityLocationIds($actor, 'kleiderkammer', 'bestand'), true), 403);
        }
    }

    public function authorizeIssuance(?User $actor, IssuedItem $issued): void
    {
        if ($this->enabled()) {
            $query = IssuedItem::withoutGlobalScopes();
            $this->scope($query, new IssuedItem, $actor, false);
            abort_unless($query->whereKey($issued->id)->exists(), 403);
        }
    }

    public function canManageIssuance(?User $actor, IssuedItem $issued): bool
    {
        if (! $this->enabled()) {
            return $actor?->canManageKleiderkammer() ?? false;
        }
        $query = IssuedItem::withoutGlobalScopes()->whereNull('deleted_at');
        $this->scope($query, new IssuedItem, $actor, false);

        return $query->whereKey($issued->id)->exists();
    }

    /** @return Builder<Stock> */
    public function issueStockQuery(?User $actor): Builder
    {
        $query = parent::issueStockQuery($actor);
        if ($this->enabled() && ! $this->isGlobal($actor)) {
            $query->whereIn('ext_location_id', $this->locationIds($actor));
        }

        return $query;
    }

    public function authorizeStockMutation(Stock $stock, string $event): void
    {
        if ($this->isGlobal($this->currentActor())) {
            return;
        }
        // Quantity changes are permitted only inside an explicitly attributed issuing operation.
        abort_unless($this->actor !== null && $event === 'updating'
            && array_diff(array_keys($stock->getDirty()), ['quantity', 'updated_at']) === []
            && $this->sourceAllowed($this->actor, 'kk_stock', $stock->id), 403);
    }

    /** All predicates use raw tables: no cached relationships, recursive scopes or auth flags. */
    public function scope(Builder $query, Model $model, ?User $actor, bool $self = true): void
    {
        if (! $this->enabled()) {
            return;
        }
        if (! $this->eligible($actor)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if ($this->isGlobal($actor)) {
            return;
        }
        $members = $this->members($actor)->select('users.id');
        $locations = $this->locationIds($actor);
        $table = $model->getTable();
        if ($model instanceof MemberSize) {
            $query->where(fn (Builder $q) => $q->whereIn("{$table}.user_id", $members)
                ->when($self, fn ($q) => $q->orWhere("{$table}.user_id", $actor->id)));
        } elseif ($model instanceof IssuedItem) {
            $query->where(function (Builder $q) use ($members, $locations, $actor, $self, $table) {
                $q->where(function (Builder $q) use ($members, $locations, $table) {
                    $q->whereIn("{$table}.user_id", $members)->where(function (Builder $q) use ($locations, $table) {
                        $q->where(fn (Builder $q) => $q->whereNull("{$table}.ext_source_stock_id")
                            ->whereIn("{$table}.item_id", $this->issuanceSources('kk_items', $locations, $table)))
                            ->orWhere(fn (Builder $q) => $q->whereNull("{$table}.item_id")
                                ->whereIn("{$table}.ext_source_stock_id", $this->issuanceSources('kk_stock', $locations, $table)));
                    });
                })->when($self, fn ($q) => $q->orWhere("{$table}.user_id", $actor->id));
            });
        } elseif ($model instanceof EquipmentMessage) {
            $issued = IssuedItem::withoutGlobalScopes();
            $this->scope($issued, new IssuedItem, $actor, $self);
            $query->whereIn("{$table}.issued_item_id", $issued->select('kk_issued_items.id'))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('kk_issued_items as owner')
                    ->whereColumn('owner.id', "{$table}.issued_item_id")->whereColumn('owner.user_id', "{$table}.user_id"));
        } elseif ($model instanceof Item) {
            $query->where(function (Builder $q) use ($locations, $actor, $self, $table) {
                $q->whereIn("{$table}.ext_location_id", $locations)
                    ->when($self, fn ($q) => $q->orWhereIn("{$table}.id", DB::table('kk_issued_items')
                        ->where('user_id', $actor->id)->whereNull('deleted_at')->select('item_id')));
            });
        } elseif ($model instanceof ItemTreatment) {
            // Treatments have no reliable personnel owner; item ownership alone is insufficient.
            $query->whereRaw('1 = 0');
        }
    }

    private function issuanceSources(string $source, array $locations, string $issuances): \Illuminate\Database\Query\Builder
    {
        return DB::table($source)->whereIn("{$source}.ext_location_id", $locations)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('users as recipient')
                ->whereColumn('recipient.id', "{$issuances}.user_id")
                ->where(fn ($query) => $query->whereColumn('recipient.ext_location_id', "{$source}.ext_location_id")
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('x_location_user as membership')
                        ->whereColumn('membership.user_id', 'recipient.id')
                        ->whereColumn('membership.location_id', "{$source}.ext_location_id"))))
            ->select("{$source}.id");
    }

    public function guardMutation(Model $model, string $event): void
    {
        if (! $this->enabled()) {
            return;
        }
        $actor = $this->currentActor();
        if ($this->isGlobal($actor)) {
            return;
        }
        if ($model instanceof MemberSize) {
            if ($event !== 'creating') {
                $this->authorizeMember($actor, User::withoutGlobalScopes()->findOrFail($model->getRawOriginal('user_id')));
            }
            $this->authorizeMember($actor, User::withoutGlobalScopes()->findOrFail($model->user_id));
        } elseif ($model instanceof EquipmentMessage && $event === 'creating') {
            $this->authorizeSelf($actor, User::withoutGlobalScopes()->findOrFail($model->user_id));
            abort_unless(DB::table('kk_issued_items')->where('id', $model->issued_item_id)
                ->where('user_id', $actor->id)->whereNull('returned_at')->whereNull('deleted_at')->exists(), 403);
        } elseif ($model instanceof EquipmentMessage) {
            abort_unless($event === 'updating'
                && array_diff(array_keys($model->getDirty()), ['handled_at', 'handled_by', 'updated_at']) === [], 403);
            $issued = IssuedItem::withoutGlobalScopes()->findOrFail($model->issued_item_id);
            $this->authorizeIssuance($actor, $issued);
            abort_unless((int) $model->user_id === (int) $issued->user_id, 403);
        } elseif ($model instanceof IssuedItem) {
            if ($event === 'creating') {
                $member = User::withoutGlobalScopes()->findOrFail($model->user_id);
                $source = $model->item_id !== null
                    ? Item::withoutGlobalScopes()->findOrFail($model->item_id)
                    : Stock::findOrFail($model->getAttribute('ext_source_stock_id'));
                abort_if($model->item_id !== null && $model->getAttribute('ext_source_stock_id') !== null, 403);
                $this->authorizeIssue($actor, $member, $source);
            } else {
                $this->authorizeIssuance($actor, $model);
                abort_unless(array_diff(array_keys($model->getDirty()), ['returned_at', 'notes', 'updated_at']) === [], 403);
            }
        } elseif ($model instanceof Item) {
            abort_if($event !== 'updating', 403);
            $this->authorizeItem($actor, $model);
            abort_unless(array_diff(array_keys($model->getDirty()), ['status', 'notes', 'updated_at']) === [], 403);
        } elseif ($model instanceof ItemTreatment) {
            abort(403);
        }
    }
}
