<?php

namespace Modules\Wartung\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ausruestung\src\Models\Equipment;

/** A Wartung that is due: generated from a template inside its Voranzeige, or planned ad hoc */
class Task extends Model
{
    protected $table = 'wm_tasks';

    protected $fillable = ['template_id', 'equipment_id', 'due_on', 'status', 'provider_id', 'notes', 'protocol_id', 'created_by', 'due_mailed_at', 'overdue_mailed_at'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'due_mailed_at' => 'datetime', 'overdue_mailed_at' => 'datetime'];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('due_on', '<', now('Europe/Berlin')->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_on->lt(now('Europe/Berlin')->startOfDay());
    }

    /** Not title(): the activity log reads a "title" attribute */
    public function label(): string
    {
        return $this->template?->name ?? ($this->notes ?: 'Wartung');
    }
}
