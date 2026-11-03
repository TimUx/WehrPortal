<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagAssignment extends Model
{
    protected $table = 'as_tag_assignments';

    protected $fillable = [
        'tag_uid',
        'standard',
        'wearer_id',
        'equipment_id',
        'is_legacy',
        'assigned_by',
        'assigned_at',
        'deactivated_by',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return ['is_legacy' => 'boolean', 'assigned_at' => 'immutable_datetime', 'deactivated_at' => 'immutable_datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /** @return BelongsTo<Wearer, $this> */
    public function wearer(): BelongsTo
    {
        return $this->belongsTo(Wearer::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
