<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $table = 'kk_stock';

    protected $fillable = [
        'item_type_id',
        'size_id',
        'group_id',
        'quantity',
    ];

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }
}
