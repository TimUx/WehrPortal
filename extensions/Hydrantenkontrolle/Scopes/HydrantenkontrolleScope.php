<?php

namespace Extensions\Hydrantenkontrolle\Scopes;

use App\Models\User;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class HydrantenkontrolleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User || InspectionService::canSeeAll($user)) {
            return;
        }

        if ($model instanceof Hydrant) {
            $this->scopeHydrants($builder, $user);
        } elseif ($model instanceof InspectionYear) {
            $this->scopeLocation($builder, $user);
        } elseif ($model instanceof Inspection) {
            $builder->whereHas('hydrant');
        }
    }

    private function scopeHydrants(Builder $query, User $user): void
    {
        $city = trim((string) $user->city);
        if ($city === '') {
            $query->whereRaw('1 = 0');
        } else {
            $column = $query->getModel()->qualifyColumn('city');
            $query->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower($city)]);
        }

        $this->scopeLocation($query, $user);
    }

    private function scopeLocation(Builder $query, User $user): void
    {
        if (! config('locations.enabled') || ! Schema::hasTable('x_locations')) {
            return;
        }

        $column = $query->getModel()->qualifyColumn('ext_location_id');
        $query->where(fn (Builder $q) => $q->whereNull($column)
            ->orWhereIn($column, app(LocationAccess::class)->visibleLocationIds($user)));
    }
}
