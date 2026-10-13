<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Trupp under Atemschutz: registered (ready), working (active) from the Anschluss, done when
 * the Atemschutz is taken off. Cylinder, consumption and reserve are taken over from the settings
 * when it is registered, so the protocol shows what the calculation was based on.
 */
class Team extends Model
{
    public const STATUSES = ['ready' => 'Bereit', 'active' => 'Im Einsatz', 'done' => 'Abgelegt'];

    protected $table = 'as_teams';

    protected $fillable = ['operation_id', 'name', 'task', 'status', 'cylinder_liters', 'consumption_lpm', 'reserve_bar',
        'started_at', 'target_reached_at', 'target_pressure', 'withdrawn_at', 'ended_at', 'csa'];

    protected function casts(): array
    {
        return ['cylinder_liters' => 'float', 'consumption_lpm' => 'integer', 'reserve_bar' => 'integer', 'target_pressure' => 'integer',
            'started_at' => 'datetime', 'target_reached_at' => 'datetime', 'withdrawn_at' => 'datetime', 'ended_at' => 'datetime', 'csa' => 'boolean'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class)->orderByDesc('is_leader')->orderBy('id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class)->orderBy('recorded_at')->orderBy('id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(Warning::class)->orderBy('raised_at');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function minutes(): ?int
    {
        return $this->started_at ? (int) $this->started_at->diffInMinutes($this->ended_at ?? now()) : null;
    }
}
