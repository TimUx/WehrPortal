<?php

namespace Extensions\Anwesenheit\Models;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A convenience template (e.g. "Übungsabend Montag") that prefills the form; the list does not stay linked to it */
class AttendanceTemplate extends Model
{
    protected $table = 'x_att_templates';

    protected $attributes = ['kind' => 'uebung', 'is_active' => true];

    protected $fillable = ['ext_location_id', 'group_id', 'name', 'kind', 'title', 'duration_minutes', 'leader_id', 'recipients', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withoutGlobalScopes();
    }
}
