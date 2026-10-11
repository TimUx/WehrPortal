<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pressure reported over the radio (or at start and end) */
class Reading extends Model
{
    public const KINDS = ['start' => 'Anschluss', 'check' => 'Druckkontrolle', 'target' => 'Ziel erreicht', 'end' => 'Abgelegt'];

    public $timestamps = false;

    protected $table = 'as_readings';

    protected $fillable = ['team_id', 'team_member_id', 'pressure', 'kind', 'recorded_at', 'recorded_by'];

    protected function casts(): array
    {
        return ['pressure' => 'integer', 'recorded_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
