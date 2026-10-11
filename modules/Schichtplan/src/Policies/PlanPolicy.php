<?php

namespace Modules\Schichtplan\src\Policies;

use App\Models\User;
use Modules\Schichtplan\src\Models\Plan;

class PlanPolicy
{
    /** Planers see every plan; everyone else the published plans they are on or lead a station of */
    public function view(User $user, Plan $plan): bool
    {
        return $user->canManageSchichtplan()
            || ($plan->isPublished() && $plan->concerns($user));
    }

    public function manage(User $user, ?Plan $plan = null): bool
    {
        return $user->canManageSchichtplan();
    }
}
