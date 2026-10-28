<?php

namespace Extensions\Locations\Scopes;

use App\Models\User;
use Extensions\Locations\Services\ClothingPersonalAccess;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ausruestung\src\Models\Location as EquipmentLocation;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Poll;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Maengelmanagement\src\Models\Device as DefectDevice;
use Modules\Schichtplan\src\Models\Plan;

/**
 * Limits a model to the Standorte of the signed-in user. Global admins and the GBI/SBI profile
 * see all locations; the scope also does nothing when nobody is signed in (console, queue,
 * scheduler, login, magic link). Unassigned rows are shared except physical storage places.
 * Auth::hasUser() never loads the user, so there is no recursion when the guard queries users.
 */
class LocationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('locations.enabled')) {
            return;
        }

        $personal = app(ClothingPersonalAccess::class);
        if ($personal->enabled() && $personal->hasActorContext() && $personal->isGlobal($personal->currentActor())) {
            return;
        }
        if ($model instanceof Item && config('locations.clothing_personal_enabled')) {
            return; // The stricter personal adapter also preserves self-service across locations.
        }
        if (! Auth::hasUser()) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User || $user->is_admin
            || app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'gemeindebrandinspektor')) {
            return;
        }

        if (($model instanceof Event || $model instanceof Poll) && $user->canManageKalender()) {
            return;
        }
        if ($model instanceof Plan && $user->canManageSchichtplan()) {
            return;
        }

        $ids = array_keys(app(LocationAccess::class)->roles($user));
        $column = $model->qualifyColumn('ext_location_id');

        if ($model instanceof Plan) {
            $builder->whereNotNull($column)->whereIn($column, $ids);

            return;
        }

        if (($model instanceof Event && Schema::hasTable('kal_event_location'))
            || ($model instanceof Poll && Schema::hasTable('kal_poll_location'))) {
            $pivot = $model instanceof Event ? 'kal_event_location' : 'kal_poll_location';
            $parentKey = $model->getTable().'.'.$model->getKeyName();
            $builder->where(function (Builder $query) use ($ids, $column, $pivot, $parentKey) {
                $query->whereNull($column)->orWhereIn($column, $ids)->orWhereExists(
                    DB::table($pivot)->selectRaw('1')->whereColumn($pivot.'.'.($pivot === 'kal_event_location' ? 'event_id' : 'poll_id'), $parentKey)
                        ->whereIn($pivot.'.location_id', $ids),
                );
            });

            return;
        }

        if ($model->getTable() === 'eb_reports'
            && $user->hasAnyModuleRole('einsatzbericht', 'wehrführung')
            && ! $user->hasModuleRole('einsatzbericht', 'wehrführung')
            && ! $user->hasModuleRole('einsatzbericht', 'einsatzleiter')) {
            $reportLocations = $user->standardRoleLocationIds('einsatzbericht', ['wehrführung']);
            $builder->whereNotNull($column)->whereIn($column, $reportLocations);

            return;
        }

        if ($model instanceof EquipmentLocation || $model instanceof DefectDevice) {
            $builder->whereIn($column, $ids);

            return;
        }

        $builder->where(function (Builder $q) use ($ids, $column, $model, $user) {
            $q->whereNull($column)->orWhereIn($column, $ids);

            if ($model instanceof User) {
                $key = $model->qualifyColumn($model->getKeyName());
                $q->orWhere($key, $user->getKey())
                    ->orWhereIn($key, DB::table('x_location_user')->whereIn('location_id', $ids)->select('user_id'));
            }
        });
    }
}
