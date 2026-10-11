<?php

namespace Modules\Fahrzeugbuchung\src\Policies;

use App\Models\User;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->is_admin && $vehicle->bookings()->doesntExist();
    }
}
