<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Modules\Einsatzbericht\src\Models\Report;

/** @property int|null $ext_location_id
 *
 * One Atemschutzüberwachung at an Einsatz or Übung, optionally linked to the Einsatzbericht */
class Operation extends Model
{
    public const KINDS = ['einsatz' => 'Einsatz', 'uebung' => 'Übung'];

    protected $table = 'as_operations';

    protected $fillable = ['title', 'kind', 'report_id', 'ext_location_id', 'started_at', 'ended_at', 'started_by', 'ended_by'];

    protected function casts(): array
    {
        return ['ext_location_id' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class)->orderBy('id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'as_operation_locations', 'operation_id', 'ext_location_id');
    }

    public function log(): HasMany
    {
        return $this->hasMany(LogEntry::class)->orderBy('noted_at')->orderBy('id');
    }

    /** @return list<int> all Standorte involved, including the first one on the operation itself */
    public function locationIds(): array
    {
        $ids = Schema::hasTable('as_operation_locations') ? $this->locations()->pluck('x_locations.id')->all() : [];
        if ($this->getAttribute('ext_location_id') !== null) {
            $ids[] = (int) $this->getAttribute('ext_location_id');
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function locationNames(): string
    {
        return $this->locations->map(fn ($l) => (string) $l->getAttribute('name'))->values()->whenEmpty(fn ($c) => collect([$this->location?->getAttribute('name')])->filter())->implode(', ');
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
