<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemType extends Model
{
    protected $table = 'kk_item_types';

    protected $fillable = [
        'category_id',
        'name',
        'code_prefix',
        'is_tracked',
        'has_size',
        'is_washable',
        'is_chainsaw_protection',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_tracked' => 'boolean',
            'has_size' => 'boolean',
            'is_washable' => 'boolean',
            'is_chainsaw_protection' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class, 'item_type_id')->orderBy('sort_order');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ItemGroup::class, 'item_type_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'item_type_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'item_type_id');
    }

    public function issuedItems(): HasMany
    {
        return $this->hasMany(IssuedItem::class, 'item_type_id');
    }
}
