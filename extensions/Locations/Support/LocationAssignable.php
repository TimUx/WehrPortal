<?php

namespace Extensions\Locations\Support;

use App\Models\User;

interface LocationAssignable
{
    public function canAssignLocation(User $user, int $locationId): bool;
}
