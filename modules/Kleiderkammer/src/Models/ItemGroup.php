<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A variant defined per item type, e.g. "Damen"/"Herren" or a manufacturer like "S-Guard". */
class ItemGroup extends Model
{
    protected $table = 'kk_item_groups';

    protected $fillable = [
        'item_type_id',
        'name',
        'sort_order',
    ];

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'group_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'group_id');
    }

    public function issuedItems(): HasMany
    {
        return $this->hasMany(IssuedItem::class, 'group_id');
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class, 'group_id');
    }

    public function isInUse(): bool
    {
        return $this->sizes()->exists()
            || MemberSize::where('group_id', $this->id)->exists()
            || $this->items()->withTrashed()->exists()
            || $this->stock()->exists()
            || $this->issuedItems()->withTrashed()->exists();
    }
}
