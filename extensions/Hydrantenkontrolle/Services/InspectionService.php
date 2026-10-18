<?php

namespace Extensions\Hydrantenkontrolle\Services;

use App\Models\User;
use App\Services\ActivityRecorder;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Support\Checklist;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionService
{
    private const ATTRIBUTES = [
        'number' => 'Nummer',
        'street' => 'Straße',
        'house_number' => 'Hausnummer',
        'location_description' => 'Standortbeschreibung',
        'latitude' => 'Breitengrad',
        'longitude' => 'Längengrad',
        'active' => 'Aktiv',
        'checked_at' => 'Prüfdatum',
        'defects' => 'Mängel',
        'defects.*' => 'Mangel',
        'notes' => 'Bemerkungen',
        'is_ok' => 'In Ordnung',
        'year' => 'Jahr',
    ];

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule('hydrantenkontrolle')
            && ($user->is_admin || $user->hasModuleRole('hydrantenkontrolle', 'hydrantenwart'));
    }

    public static function locationsOn(): bool
    {
        return (bool) config('locations.enabled') && Schema::hasTable('x_locations');
    }

    public function saveHydrant(User $by, array $data, ?Hydrant $hydrant = null, ?int $expectedVersion = null): Hydrant
    {
        Gate::forUser($by)->authorize('manage-hydrantenkontrolle');

        try {
            return DB::transaction(function () use ($by, $data, $hydrant, $expectedVersion) {
                if ($hydrant) {
                    $hydrant = $this->scoped(Hydrant::query(), $by)->lockForUpdate()->findOrFail($hydrant->getKey());
                    $this->assertVersion($hydrant, $expectedVersion);
                    if (array_key_exists('ext_location_id', $data) && $data['ext_location_id'] != $hydrant->ext_location_id) {
                        throw ValidationException::withMessages(['ext_location_id' => 'Der Standort eines Hydranten darf nicht geändert werden.']);
                    }
                }

                $values = $this->trimmed($data, ['number', 'street', 'house_number', 'location_description', 'latitude', 'longitude']);
                $values['active'] = $data['active'] ?? $hydrant?->active ?? true;
                $values = Validator::make($values, [
                    'number' => ['required', 'string', 'max:100'],
                    'street' => ['nullable', 'string', 'max:255'],
                    'house_number' => ['nullable', 'string', 'max:50'],
                    'location_description' => ['nullable', 'string', 'max:10000'],
                    'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                    'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
                    'active' => ['required', 'boolean'],
                ], [], self::ATTRIBUTES)->validate();
                if (! filled($values['street']) && ! filled($values['location_description']) && $values['latitude'] === null) {
                    throw ValidationException::withMessages(['location_description' => 'Bitte eine Straße, eine Standortbeschreibung oder vollständige Koordinaten angeben.']);
                }

                if (! $hydrant) {
                    $locationId = $this->locationId($by);
                    if (array_key_exists('ext_location_id', $data) && $data['ext_location_id'] != $locationId) {
                        throw ValidationException::withMessages(['ext_location_id' => 'Bitte zuerst zum gewünschten Standort wechseln.']);
                    }
                    $hydrant = new Hydrant(['ext_location_id' => $locationId]);
                }
                $hydrant->fill($values);
                if ($hydrant->exists) {
                    $hydrant->version++;
                }
                $this->persist($hydrant, $by, (string) $hydrant->number);

                return $hydrant;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['number' => 'Diese Hydrantennummer ist an diesem Standort bereits vergeben.']);
            }
            throw $e;
        }
    }

    public function openYear(User $by, int $year): InspectionYear
    {
        Gate::forUser($by)->authorize('manage-hydrantenkontrolle');
        Validator::make(['year' => $year], ['year' => ['required', 'integer', 'between:1900,9999']], [], self::ATTRIBUTES)->validate();

        return DB::transaction(function () use ($by, $year) {
            $locationId = $this->locationId($by);
            // firstOrCreate uses a savepoint on a unique-key race; the row lock serializes snapshot appends.
            $record = $this->scoped(InspectionYear::query(), $by)->firstOrCreate(['ext_location_id' => $locationId, 'year' => $year]);
            if ($record->wasRecentlyCreated) {
                app(ActivityRecorder::class)->record('created', InspectionYear::class, $record->id, (string) $year, ['year' => [null, $year]], userId: $by->id);
            }
            $record = $this->scoped(InspectionYear::query(), $by)->lockForUpdate()->findOrFail($record->id);
            $existing = $record->inspections()->pluck('hydrant_id');
            $hydrants = $this->scoped(Hydrant::query(), $by)
                ->when(self::locationsOn(), fn (Builder $query) => $query->where('ext_location_id', $locationId))
                ->where('active', true)->whereNotIn('id', $existing)->orderBy('id')->get();
            foreach ($hydrants as $hydrant) {
                $inspection = new Inspection([
                    'inspection_year_id' => $record->id,
                    'hydrant_id' => $hydrant->id,
                    'snapshot' => $hydrant->only(['number', 'street', 'house_number', 'location_description', 'latitude', 'longitude']),
                ]);
                $this->persist($inspection, $by, $year.' / '.$hydrant->number);
            }

            return $record;
        });
    }

    public function saveInspection(User $by, Inspection $inspection, array $data, int $expectedVersion): Inspection
    {
        Gate::forUser($by)->authorize('manage-hydrantenkontrolle');

        return DB::transaction(function () use ($by, $inspection, $data, $expectedVersion) {
            $inspection = Inspection::query()->whereHas('year', fn (Builder $query) => $this->scoped($query, $by))
                ->lockForUpdate()->findOrFail($inspection->getKey());
            $this->assertVersion($inspection, $expectedVersion);
            $values = $this->trimmed($data, ['checked_at', 'notes']);
            $values['is_ok'] = $data['is_ok'] ?? false;
            $values['defects'] = $data['defects'] ?? [];
            $values = Validator::make($values, [
                'checked_at' => ['nullable', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:10000'],
                'is_ok' => ['required', 'boolean'],
                'defects' => ['present', 'array', 'list'],
                'defects.*' => ['string', 'distinct', Rule::in(array_keys(Checklist::DEFECTS))],
            ], [], self::ATTRIBUTES)->validate();
            $hasResult = (bool) $values['is_ok'] || $values['defects'] !== [];
            if ($values['is_ok'] && $values['defects'] !== []) {
                throw ValidationException::withMessages(['defects' => '„In Ordnung“ und Mängel können nicht gleichzeitig ausgewählt werden.']);
            }
            if ($hasResult && $values['checked_at'] === null) {
                throw ValidationException::withMessages(['checked_at' => 'Bitte das Kontrolldatum angeben.']);
            }
            if (! $hasResult && $values['checked_at'] !== null) {
                throw ValidationException::withMessages(['checked_at' => 'Ein Kontrolldatum erfordert ein Ergebnis.']);
            }
            if ($values['checked_at'] !== null && (int) substr($values['checked_at'], 0, 4) !== $inspection->year->year) {
                throw ValidationException::withMessages(['checked_at' => 'Das Kontrolldatum muss im ausgewählten Kontrolljahr liegen.']);
            }
            $inspection->fill($values);
            $inspection->inspector_id = $by->id;
            $inspection->inspector_name = $by->name;
            $inspection->version++;
            $this->persist($inspection, $by, $inspection->year->year.' / '.($inspection->snapshot['number'] ?? ''));

            return $inspection;
        });
    }

    private function assertVersion(Model $model, ?int $expected): void
    {
        if ($expected === null || $model->getAttribute('version') !== $expected) {
            throw ValidationException::withMessages(['version' => 'Der Datensatz wurde inzwischen geändert. Bitte neu laden und die Änderungen erneut eingeben.']);
        }
    }

    private function locationId(User $user): ?int
    {
        return self::locationsOn() ? app(LocationAccess::class)->currentLocationId($user) : null;
    }

    private function scoped(Builder $query, User $user): Builder
    {
        if (self::locationsOn() && ! $user->is_admin) {
            $column = $query->getModel()->qualifyColumn('ext_location_id');
            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereIn($column, app(LocationAccess::class)->visibleLocationIds($user)));
        }

        return $query;
    }

    private function trimmed(array $data, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            $values[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return $values;
    }

    private function persist(Model $model, User $by, string $label): void
    {
        $created = ! $model->exists;
        $changes = [];
        foreach ($model->getDirty() as $field => $value) {
            $changes[$field] = [$created ? null : $model->getOriginal($field), $model->getAttribute($field)];
        }
        $model->save();
        app(ActivityRecorder::class)->record($created ? 'created' : 'updated', $model::class, $model->getKey(), $label, $changes, userId: $by->id);
    }
}
