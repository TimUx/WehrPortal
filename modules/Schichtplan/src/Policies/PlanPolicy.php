<?php

namespace Modules\Schichtplan\src\Policies;

use App\Models\User;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\SignupService;

class PlanPolicy
{
    /**
     * Planers see every plan; everyone else the published plans they are on or lead a station of,
     * and the published self sign-up plans of their target group
     */
    public function view(User $user, Plan $plan): bool
    {
        return $user->canManageSchichtplan()
            || ($plan->isPublished() && ($plan->concerns($user) || ($plan->isSelfSignup() && SignupService::mayJoin($user, $plan))));
    }

    public function manage(User $user, ?Plan $plan = null): bool
    {
        return $user->canManageSchichtplan();
    }
}
