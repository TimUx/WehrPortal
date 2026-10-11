<?php

namespace Modules\Maengelmanagement\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;

/** One line of a defect's history */
class DefectEvent extends Model
{
    public const REPORTED = 'reported';

    public const STATUS = 'status';

    public const URGENCY = 'urgency';

    public const ASSIGNED = 'assigned';

    public const COMMENT = 'comment';

    protected $table = 'mm_defect_events';

    protected $fillable = ['defect_id', 'user_id', 'type', 'from_value', 'to_value', 'body'];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(Defect::class, 'defect_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One sentence for the timeline, without the author */
    public function summary(): string
    {
        return match ($this->type) {
            self::REPORTED => 'hat den Mangel gemeldet',
            self::STATUS => 'hat den Status geändert: '.(DefectStatus::tryFrom((string) $this->from_value)?->label() ?? '–').' → '.(DefectStatus::tryFrom((string) $this->to_value)?->label() ?? '–'),
            self::URGENCY => 'hat die Dringlichkeit geändert: '.(Urgency::tryFrom((string) $this->from_value)?->label() ?? '–').' → '.(Urgency::tryFrom((string) $this->to_value)?->label() ?? '–'),
            self::ASSIGNED => $this->to_value ? 'hat den Mangel zugewiesen an '.$this->to_value : 'hat die Zuweisung aufgehoben',
            default => 'hat kommentiert',
        };
    }
}
