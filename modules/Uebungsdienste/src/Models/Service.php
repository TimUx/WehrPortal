<?php

namespace Modules\Uebungsdienste\src\Models;

use App\Models\Group;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Extensions\Locations\Models\Location;

/**
 * One Übungsdienst: date, start and end (Berlin time), place, Kleidung, target group, topic and who
 * is responsible. Without the topic it is also a Termin in the Kalender of the target group.
 *
 * @property Carbon $date
 */
class Service extends Model
{
    protected $table = 'ud_services';

    /** Length when no end is given */
    public const DEFAULT_MINUTES = 120;

    protected $fillable = ['date', 'starts_at', 'ends_at', 'location', 'place_id', 'dress_code_ids', 'group_ids', 'function_ids', 'topic', 'event_id', 'calendar_synced_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'calendar_synced_at' => 'datetime', 'dress_code_ids' => 'array', 'group_ids' => 'array', 'function_ids' => 'array'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** "19:00–21:00 Uhr" */
    public function timeLabel(): ?string
    {
        return $this->starts_at ? $this->starts_at.($this->ends_at ? '–'.$this->ends_at : '').' Uhr' : null;
    }

    /** "Jugendfeuerwehr, Maschinist" or null for everyone */
    public function audienceLabel(): ?string
    {
        $locationsEnabled = config('locations.enabled') && Schema::hasTable('x_locations');
        $groups = Group::whereIn('id', $this->group_ids ?? [])
            ->whereRaw('lower(name) = ?', ['einsatzabteilung'])->orderBy('name')
            ->get($locationsEnabled ? ['name', 'ext_location_id'] : ['name']);
        $locations = $locationsEnabled
            ? Location::whereIn('id', $groups->pluck('ext_location_id')->filter())->pluck('name', 'id')
            : collect();
        $names = $groups->map(function (Group $group) use ($locations) {
            $location = $locations->get($group->getAttribute('ext_location_id'));

            return $group->name.($location ? ' · '.$location : '');
        })->all();

        return $names === [] ? null : implode(', ', $names);
    }

    public function responsible(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ud_service_user', 'service_id', 'user_id')->orderBy('name');
    }

    /** "Di, 14.10.2026" */
    public function dateLabel(): string
    {
        return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$this->date->dayOfWeek].', '.$this->date->format('d.m.Y');
    }
}
