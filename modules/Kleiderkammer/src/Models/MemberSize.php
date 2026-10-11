<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's clothing size for one item type ("Konfektionsgröße"). */
class MemberSize extends Model
{
    protected $table = 'kk_member_sizes';

    protected $fillable = [
        'user_id',
        'item_type_id',
        'group_id',
        'size_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }
}
