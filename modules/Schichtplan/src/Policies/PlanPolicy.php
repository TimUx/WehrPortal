<?php

namespace Modules\Schichtplan\src\Policies;

use App\Models\User;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\PlanLocationAccess;
use Modules\Schichtplan\src\Services\SignupService;

class PlanPolicy
{
    /**
     * Planers see every plan; everyone else the published plans they are on or lead a station of,
     * and the published self sign-up plans of their target group
     */
    public function view(User $user, Plan $plan): bool
    {
        return app(PlanLocationAccess::class)->canManage($user, $plan->ext_location_id)
            || ($plan->isPublished() && ($plan->concerns($user) || ($plan->isSelfSignup() && SignupService::mayJoin($user, $plan))));
    }

    public function manage(User $user, ?Plan $plan = null): bool
    {
        return $plan === null
            ? $user->canManageAnySchichtplan()
            : $user->canManageSchichtplanAt($plan->ext_location_id);
    }
}
