<?php

namespace Extensions\Locations\Support;

use App\Models\User;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/** The creating/updating hooks: new records get the current Standort; nobody writes into a foreign one */
class AssignsLocation
{
    private static bool $ready = false;

    /** Forget that the table exists, e.g. after migrate:fresh */
    public static function reset(): void
    {
        self::$ready = false;
    }

    public static function creating(Model $model): void
    {
        if (! self::ready()) {
            return;
        }

        $access = app(LocationAccess::class);
        $user = Auth::hasUser() ? Auth::user() : null;
        $user = $user instanceof User ? $user : null;

        if ($model->getAttribute('ext_location_id') === null) {
            $model->setAttribute('ext_location_id', $access->currentLocationId($user));

            return;
        }

        self::authorize($model, $user);
    }

    public static function updating(Model $model): void
    {
        if ($model->isDirty('ext_location_id') && $model->getAttribute('ext_location_id') !== null && self::ready()) {
            $user = Auth::hasUser() ? Auth::user() : null;
            self::authorize($model, $user instanceof User ? $user : null);
        }
    }

    private static function authorize(Model $model, ?User $user): void
    {
        $id = (int) $model->getAttribute('ext_location_id');

        if ($user && ! $user->is_admin && ! app(LocationAccess::class)->canSee($user, $id)) {
            throw new AuthorizationException('Kein Zugriff auf diesen Standort.');
        }
    }

    private static function ready(): bool
    {
        if (! self::$ready) {
            self::$ready = Schema::hasTable('x_locations');
        }

        return self::$ready;
    }
}
