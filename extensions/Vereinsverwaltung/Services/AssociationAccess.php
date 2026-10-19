<?php

namespace Extensions\Vereinsverwaltung\Services;

use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Association;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssociationAccess
{
    public function associationsFor(User $user): Builder
    {
        $query = Association::query()->withoutGlobalScopes()->with('location');

        if (! $user->is_admin) {
            $query->whereIn('id', DB::table('x_vv_association_user')
                ->where('user_id', $user->id)
                ->select('association_id'));
        }

        return $query;
    }

    public function canManage(User $user, ?Association $association = null): bool
    {
        if (! $user->is_active || ! app(MembershipService::class)->moduleIsActive()) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        if (! $association) {
            return DB::table('x_vv_association_user')->where('user_id', $user->id)->exists();
        }

        return DB::table('x_vv_association_user')
            ->where('user_id', $user->id)
            ->where('association_id', $association->id)
            ->exists();
    }

    public function association(User $user, int $associationId): Association
    {
        abort_unless($this->canManage($user), 403);

        return $this->associationsFor($user)->findOrFail($associationId);
    }

    public function associationForLocation(?int $locationId): ?Association
    {
        if (! $locationId) {
            return null;
        }

        return Association::query()->withoutGlobalScopes()->where('location_id', $locationId)->first();
    }

    public function syncModuleAccess(User $user): void
    {
        if (DB::table('x_vv_association_user')->where('user_id', $user->id)->exists()) {
            $user->moduleAccess()->updateOrCreate(['module' => 'verein'], ['role' => 'vereinsvorstand']);
        } else {
            $user->moduleAccess()->where('module', 'verein')->delete();
        }
    }
}
