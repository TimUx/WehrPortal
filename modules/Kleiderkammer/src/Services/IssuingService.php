<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Enums\MemberStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;

class IssuingService
{
    /**
     * Issue a tracked item to a member.
     * The item must be available and not soft-deleted.
     */
    public function issueTracked(User $member, Item $item, string $notes = '', ?User $actor = null): IssuedItem
    {
        $actor ??= auth()->user();
        app(StockAccess::class)->authorizePersonal($actor);
        $personal = app(PersonalAccess::class);

        return $personal->asActor($actor, fn () => DB::transaction(function () use ($member, $item, $notes, $actor, $personal) {
            $member = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($member->id);
            $this->ensureCanReceive($member);
            $fresh = Item::withoutGlobalScopes()->whereNull('deleted_at')->lockForUpdate()->findOrFail($item->id);
            $personal->authorizeIssue($actor, $member, $fresh);

            if ($fresh->status !== ItemStatus::Available) {
                throw new \DomainException("Item {$fresh->code} is not available (status: {$fresh->status->value}).");
            }

            $issuedItem = IssuedItem::create([
                'user_id' => $member->id,
                'item_id' => $fresh->id,
                'quantity' => 1,
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            $fresh->update(['status' => ItemStatus::Issued]);

            return $issuedItem;
        }));
    }

    /**
     * Issue stock items (non-tracked) to a member.
     * Decreases the stock quantity accordingly.
     */
    public function issueStock(User $member, ItemType $itemType, ?Size $size, int $quantity, string $notes = '', ?ItemGroup $group = null, ?int $stockId = null, ?User $actor = null): IssuedItem
    {
        $actor ??= auth()->user();
        $access = app(StockAccess::class);
        $access->authorizePersonal($actor);
        $personal = app(PersonalAccess::class);

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        return $personal->asActor($actor, fn () => $access->asActor($actor, fn () => DB::transaction(function () use ($member, $itemType, $size, $quantity, $notes, $group, $stockId, $actor, $access, $personal) {
            $member = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($member->id);
            $this->ensureCanReceive($member);
            abort_if($personal->enabled() && $stockId === null, 403);
            $stock = Stock::lockForUpdate()
                ->when($stockId !== null, fn ($query) => $query->whereKey($stockId))
                ->where('item_type_id', $itemType->id)
                ->where('size_id', $size?->id)
                ->where('group_id', $group?->id)
                ->first();

            if ($stock !== null) {
                $personal->authorizeIssue($actor, $member, $stock);
                $access->authorizeStock($actor, $stock);
            } elseif ($access->enabled() || $personal->enabled()) {
                abort(403);
            }
            $available = $stock?->quantity ?? 0;

            if ($available < $quantity) {
                throw new \DomainException("Not enough stock (available: {$available}, requested: {$quantity}).");
            }

            $issuedItem = new IssuedItem([
                'user_id' => $member->id,
                'item_type_id' => $itemType->id,
                'size_id' => $size?->id,
                'group_id' => $group?->id,
                'quantity' => $quantity,
                'issued_at' => now(),
                'notes' => $notes,
            ]);
            if ($issuedItem->getConnection()->getSchemaBuilder()->hasColumn($issuedItem->getTable(), 'ext_source_stock_id')) {
                $issuedItem->forceFill(['ext_source_stock_id' => $stock->id]);
            }
            $issuedItem->save();

            $stock->decrement('quantity', $quantity);

            return $issuedItem;
        })));
    }

    /**
     * Return an issued item or stock quantity.
     * Sets returned_at and, for tracked items, flips the status back to available.
     * For stock items, restores the quantity.
     */
    public function return(IssuedItem $issuedItem, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $access = app(StockAccess::class);
        $access->authorizePersonal($actor);
        $personal = app(PersonalAccess::class);
        $personal->asActor($actor, fn () => $access->asActor($actor, fn () => DB::transaction(function () use ($issuedItem, $actor, $access, $personal) {
            $fresh = IssuedItem::withoutGlobalScopes()->whereNull('deleted_at')->lockForUpdate()->findOrFail($issuedItem->id);
            $personal->authorizeIssuance($actor, $fresh);

            if ($fresh->returned_at !== null) {
                throw new \DomainException('This record has already been returned.');
            }

            $fresh->update(['returned_at' => now()]);

            $sourceStockId = $fresh->getAttributes()['ext_source_stock_id'] ?? null;
            if ($sourceStockId !== null) {
                if ($fresh->item_id !== null) {
                    throw new \DomainException('A tracked item cannot have a stock source.');
                }
                $stock = Stock::lockForUpdate()->findOrFail($sourceStockId);
                $access->authorizeStock($actor, $stock);
                $stock->increment('quantity', $fresh->quantity);
            } elseif ($fresh->item_id !== null) {
                Item::withoutGlobalScopes()->where('id', $fresh->item_id)->update(['status' => ItemStatus::Available->value]);
            } else {
                $access->authorizeLegacyReturn($actor);
                if ($fresh->item_type_id !== null) {
                    Stock::firstOrCreate(
                        ['item_type_id' => $fresh->item_type_id, 'size_id' => $fresh->size_id, 'group_id' => $fresh->group_id],
                        ['quantity' => 0],
                    )->increment('quantity', $fresh->quantity);
                }
            }
        })));
    }

    /**
     * Forcibly return all open items from a member.
     * Typically called when a member leaves (DeactivateMemberAction).
     */
    public function takeBack(User $member, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        app(StockAccess::class)->authorizePersonal($actor);
        app(PersonalAccess::class)->authorizeMember($actor, $member);
        DB::transaction(function () use ($member, $actor) {
            // Never use a scoped relation here: mixed-source returns must fail atomically.
            IssuedItem::withoutGlobalScopes()->where('user_id', $member->id)->whereNull('returned_at')->whereNull('deleted_at')
                ->lockForUpdate()->get()->each(function (IssuedItem $issuedItem) use ($actor) {
                    $this->return($issuedItem, $actor);
                });
        });
    }

    private function ensureCanReceive(User $member): void
    {
        if ($member->status !== MemberStatus::Active) {
            throw new \DomainException("{$member->name} is not active and cannot receive items.");
        }
    }

    /**
     * Retire a tracked item (worn out / disposed of).
     * The item must not currently be issued.
     */
    public function retire(Item $item, string $notes = '', ?User $actor = null): void
    {
        $actor ??= auth()->user();
        app(StockAccess::class)->authorizePersonal($actor);
        $personal = app(PersonalAccess::class);
        $personal->asActor($actor, fn () => DB::transaction(function () use ($item, $notes, $actor, $personal) {
            $fresh = Item::withoutGlobalScopes()->whereNull('deleted_at')->lockForUpdate()->findOrFail($item->id);
            $personal->authorizeItem($actor, $fresh);

            if ($fresh->status === ItemStatus::Issued) {
                throw new \DomainException("Item {$fresh->code} is currently issued and cannot be retired.");
            }

            $update = ['status' => ItemStatus::Retired];
            if ($notes !== '') {
                $update['notes'] = $notes;
            }

            $fresh->update($update);
        }));
    }
}
