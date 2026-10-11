<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Einsatzbericht\src\Models\Report;

/** One Atemschutzüberwachung at an Einsatz or Übung, optionally linked to the Einsatzbericht */
class Operation extends Model
{
    public const KINDS = ['einsatz' => 'Einsatz', 'uebung' => 'Übung'];

    protected $table = 'as_operations';

    protected $fillable = ['title', 'kind', 'report_id', 'started_at', 'ended_at', 'started_by', 'ended_by'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class)->orderBy('id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }
}
