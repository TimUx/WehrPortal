<?php

namespace Extensions\Locations\Models;

use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class Location extends Model
{
    protected $table = 'x_locations';

    protected $fillable = ['name', 'short_name', 'slug', 'is_default', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Exactly one default Standort
        static::saved(function (Location $location) {
            if ($location->is_default) {
                static::query()->whereKeyNot($location->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });

        static::deleting(function (Location $location) {
            if ($location->is_default || static::query()->count() <= 1) {
                throw ValidationException::withMessages(['location' => 'Der Hauptstandort bzw. der letzte Standort kann nicht gelöscht werden.']);
            }
        });

        // Extensions\ models are not picked up by the core activity log, so changes are written here
        $log = fn (string $event) => function (Location $location) use ($event) {
            $changes = $event === 'updated' ? collect($location->getChanges())->except('updated_at')
                ->mapWithKeys(fn ($new, $field) => [$field => [$location->getOriginal($field), $new]])->all() : [];
            if ($event !== 'updated' || $changes !== []) {
                app(ActivityRecorder::class)->record($event, static::class, $location->getKey(), $location->name, $changes);
            }
        };
        static::created($log('created'));
        static::updated($log('updated'));
        static::deleted($log('deleted'));
    }

    /** The default Standort; created when missing so one always exists */
    public static function default(): self
    {
        $default = static::query()->where('is_default', true)->first();

        if (! $default) {
            $default = static::query()->orderBy('sort_order')->orderBy('id')->first();
            if ($default) {
                $default->update(['is_default' => true]);
            } else {
                $default = static::create(['name' => 'Hauptstandort', 'slug' => 'default', 'is_default' => true]);
            }
        }

        return $default;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'x_location_user', 'location_id', 'user_id')->withPivot('role')->orderBy('name');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'ext_location_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'ext_location_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
