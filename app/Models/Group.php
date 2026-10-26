<?php

namespace App\Models;

use Closure;
use Database\Factories\GroupFactory;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'sort_order',
        'ext_location_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Group $group): void {
            if ($group->getAttribute('ext_location_id') || config('locations.enabled') || ! self::locationsSchemaExists()) {
                return;
            }

            $group->setAttribute(
                'ext_location_id',
                DB::table('x_locations')->where('is_default', true)->value('id')
                    ?? DB::table('x_locations')->orderBy('sort_order')->orderBy('id')->value('id'),
            );
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }

    public function displayName(): string
    {
        if (! self::locationsAvailable()) {
            return $this->name;
        }

        $location = $this->relationLoaded('location') ? $this->location : $this->location()->first();

        $name = (string) $this->getAttribute('name');

        return $location ? "{$name} ({$location->getAttribute('name')})" : $name;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->displayName();
    }

    /** @return array<int, string> */
    public static function options(?Closure $constrain = null): array
    {
        /** @var Builder<Group> $query */
        $query = static::query();
        if (self::locationsAvailable()) {
            $query->with('location');
        }
        $constrain?->__invoke($query);

        return $query->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (Group $group) => [$group->getKey() => $group->displayName()])
            ->all();
    }

    public static function locationsAvailable(): bool
    {
        return (bool) config('locations.enabled')
            && self::locationsSchemaExists();
    }

    private static function locationsSchemaExists(): bool
    {
        return Schema::hasTable('x_locations') && Schema::hasColumn('groups', 'ext_location_id');
    }
}
