<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    protected $table = 'kk_item_categories';

    protected $fillable = [
        'name',
        'sort_order',
    ];

    public function itemTypes(): HasMany
    {
        return $this->hasMany(ItemType::class, 'category_id');
    }
}
