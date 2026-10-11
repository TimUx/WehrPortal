<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's note to the Kleiderwart about a piece of equipment issued to them */
class EquipmentMessage extends Model
{
    protected $table = 'kk_equipment_messages';

    protected $fillable = ['user_id', 'issued_item_id', 'item_label', 'body', 'handled_at', 'handled_by'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuedItem(): BelongsTo
    {
        return $this->belongsTo(IssuedItem::class);
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }
}
