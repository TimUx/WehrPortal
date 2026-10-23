<?php

namespace Extensions\Locations\Scopes;

use App\Models\User;
use Extensions\Locations\Services\ClothingPersonalAccess;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Models\Item;

/**
 * Limits a model to the Standorte of the signed-in user. It does nothing for global admins and
 * whenever nobody is signed in (console, queue, scheduler, login, magic link). Rows without a
 * Standort are shared and stay visible. Auth::hasUser() never loads the user, so there is no
 * recursion when the guard itself queries the users table.
 */
class LocationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
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
        if (! $user instanceof User || $user->is_admin) {
            return;
        }

        $ids = array_keys(app(LocationAccess::class)->roles($user));
        $column = $model->qualifyColumn('ext_location_id');

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
