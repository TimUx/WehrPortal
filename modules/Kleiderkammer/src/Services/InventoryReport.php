<?php

namespace Modules\Kleiderkammer\src\Services;

use Illuminate\Support\Collection;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\ItemType;

class InventoryReport
{
    /**
     * Counts per item type for tracked items.
     * Returns a collection of objects with:
     *   item_type_id, name, total, available, issued, retired
     */
    public function trackedByType(): Collection
    {
        return ItemType::where('is_tracked', true)
            ->with(['items' => fn ($q) => $q->withTrashed()])
            ->get()
            ->map(fn (ItemType $type) => (object) [
                'item_type_id' => $type->id,
                'name' => $type->name,
                'total' => $type->items->count(),
                'available' => $type->items->where('status', ItemStatus::Available)->count(),
                'issued' => $type->items->where('status', ItemStatus::Issued)->count(),
                'retired' => $type->items->where('status', ItemStatus::Retired)->count(),
            ]);
    }

    /**
     * Stock quantities for non-tracked item types, grouped by type + size.
     */
    public function stockByType(): Collection
    {
        return ItemType::where('is_tracked', false)
            ->with(['stock.size'])
            ->get()
            ->flatMap(fn (ItemType $type) => $type->stock->map(fn ($row) => (object) [
                'item_type_id' => $type->id,
                'name' => $type->name,
                'size' => $row->size?->name,
                'quantity' => $row->quantity,
            ]));
    }

    /**
     * Open issuance records (items still out with members), ordered by issued_at.
     */
    public function openLoans(): Collection
    {
        return IssuedItem::with(['user', 'item.itemType', 'itemType', 'size'])
            ->whereNull('returned_at')
            ->orderBy('issued_at')
            ->get();
    }

    /**
     * Summary counts: total tracked items, available, issued, retired; total stock rows.
     *
     * @return array{tracked_total: int, available: int, issued: int, retired: int, open_loans: int}
     */
    public function summary(): array
    {
        return [
            'tracked_total' => $this->trackedByType()->sum('total'),
            'available' => $this->trackedByType()->sum('available'),
            'issued' => $this->trackedByType()->sum('issued'),
            'retired' => $this->trackedByType()->sum('retired'),
            'open_loans' => IssuedItem::whereNull('returned_at')->count(),
        ];
    }
}
