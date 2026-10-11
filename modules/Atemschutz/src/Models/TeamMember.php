<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamMember extends Model
{
    protected $table = 'as_team_members';

    protected $fillable = ['team_id', 'user_id', 'name', 'is_leader', 'start_pressure', 'end_pressure', 'override_reason'];

    protected function casts(): array
    {
        return ['is_leader' => 'boolean', 'start_pressure' => 'integer', 'end_pressure' => 'integer'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class)->orderBy('recorded_at')->orderBy('id');
    }
}
