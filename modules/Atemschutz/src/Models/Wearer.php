<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Kamerad in the Atemschutz pool: qualification history, G26.3 (only the dates, no findings)
 * and RFID tags for registration at the Einsatz. Unterweisungen and Belastungsübungen are Trainings.
 */
class Wearer extends Model
{
    protected $table = 'as_wearers';

    protected $fillable = ['user_id', 'notes', 'reminded', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'reminded' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class, 'user_id', 'user_id');
    }

    /** @return HasMany<WearerExamination, $this> */
    public function examinations(): HasMany
    {
        return $this->hasMany(WearerExamination::class)->orderByRaw('examined_on DESC NULLS LAST')->orderByDesc('id');
    }

    /** @return HasMany<WearerQualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(WearerQualification::class)->orderByRaw('qualified_on DESC NULLS LAST')->orderByDesc('id');
    }

    /** @return HasMany<TagAssignment, $this> */
    public function tagAssignments(): HasMany
    {
        return $this->hasMany(TagAssignment::class)->orderByDesc('assigned_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('user', fn ($q) => $q->where('is_active', true));
    }
}
