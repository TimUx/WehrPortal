<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Kamerad in the Atemschutz pool: AGT-Lehrgang, G26.3 (only the dates, no findings) and the
 * RFID tag for the registration at the Einsatz. Unterweisungen and Belastungsübungen are Trainings.
 */
class Wearer extends Model
{
    protected $table = 'as_wearers';

    protected $fillable = ['user_id', 'is_agt', 'agt_qualified_on', 'g263_examined_on', 'g263_valid_until', 'rfid_uid', 'notes', 'reminded', 'is_active', 'is_csa', 'csa_qualified_on'];

    protected function casts(): array
    {
        return ['is_agt' => 'boolean', 'is_active' => 'boolean', 'agt_qualified_on' => 'date', 'g263_examined_on' => 'date', 'g263_valid_until' => 'date', 'reminded' => 'array', 'is_csa' => 'boolean', 'csa_qualified_on' => 'date'];
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereHas('user', fn ($q) => $q->where('is_active', true));
    }
}
