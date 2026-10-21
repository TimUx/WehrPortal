<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

/**
 * A member's note to the Kleiderwart about a piece of equipment issued to them: a Mangel (defect) or a
 * Wäsche request (washing, the body is the reason). Handled = erledigt / gewaschen.
 *
 * @property string $kind
 */
class EquipmentMessage extends Model
{
    public const KINDS = ['defect' => 'Mangel', 'washing' => 'Wäsche'];

    protected $table = 'kk_equipment_messages';

    protected $fillable = ['user_id', 'issued_item_id', 'item_label', 'body', 'kind', 'handled_at', 'handled_by'];

    protected $attributes = ['kind' => 'defect'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        $relation = $this->belongsTo(User::class);
        if (app(PersonalAccess::class)->enabled()) {
            $relation->withoutGlobalScopes();
        }

        return $relation;
    }

    public function issuedItem(): BelongsTo
    {
        return $this->belongsTo(IssuedItem::class);
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    public function isWashing(): bool
    {
        return $this->kind === 'washing';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** offen/erledigt, for Wäsche angefordert/gewaschen */
    public function stateLabel(): string
    {
        return $this->isWashing() ? ($this->isHandled() ? 'gewaschen' : 'angefordert') : ($this->isHandled() ? 'erledigt' : 'offen');
    }
}
