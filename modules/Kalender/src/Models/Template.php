<?php

namespace Modules\Kalender\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Terminvorlage for regular events (e.g. "Arbeitsdienst Gerätehaus", "Übungsdienst Jugend"):
 * title, type, start time and duration, place, description, Rückmeldung and target group.
 */
class Template extends Model
{
    protected $table = 'kal_templates';

    protected $fillable = ['name', 'data', 'created_by'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
