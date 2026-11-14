<?php

namespace Extensions\Vereinsverwaltung\Services;

use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Association;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Admins and the Vorsitz (with its Stellvertretung) of a Verein put its Vorstand together */
    public function canManageBoard(User $user, Association $association): bool
    {
        if (! $this->canManage($user, $association)) {
            return false;
        }

        return $user->is_admin || DB::table('x_vv_association_user')->where('user_id', $user->id)
            ->where('association_id', $association->id)->where('office', 'vorsitz')->exists();
    }

    /** Adds a Kamerad to the Vorstand or changes the Amt */
    public function setBoardMember(User $by, Association $association, int $userId, ?string $office, bool $deputy = false): void
    {
        $this->authorizeBoardChange($by, $association, $userId);
        $office = $office === '' ? null : $office;
        if ($office !== null && ! isset(Association::OFFICES[$office])) {
            throw ValidationException::withMessages(['office' => 'Dieses Amt gibt es nicht.']);
        }

        $member = User::query()->withoutGlobalScopes()->where('is_active', true)->find($userId);
        if (! $member) {
            throw ValidationException::withMessages(['user_id' => 'Nur aktive Kameraden können dem Vorstand angehören.']);
        }
        // The Vorsitz chooses among the Kameraden of the Verein's Standort; admins among everyone
        $seated = DB::table('x_vv_association_user')->where('association_id', $association->id)->where('user_id', $userId)->exists();
        if (! $by->is_admin && ! $seated && (int) $member->getAttribute('ext_location_id') !== (int) $association->location_id) {
            throw ValidationException::withMessages(['user_id' => 'Bitte einen Kameraden des Standorts dieses Vereins wählen.']);
        }

        DB::table('x_vv_association_user')->updateOrInsert(
            ['association_id' => $association->id, 'user_id' => $userId],
            ['office' => $office, 'is_deputy' => $office !== null && $deputy],
        );
        $this->syncModuleAccess($member);
    }

    public function removeBoardMember(User $by, Association $association, int $userId): void
    {
        $this->authorizeBoardChange($by, $association, $userId);
        DB::table('x_vv_association_user')->where('association_id', $association->id)->where('user_id', $userId)->delete();
        if ($member = User::query()->withoutGlobalScopes()->find($userId)) {
            $this->syncModuleAccess($member);
        }
    }

    private function authorizeBoardChange(User $by, Association $association, int $userId): void
    {
        abort_unless($this->canManageBoard($by, $association), 403);
        // Nobody takes the own Amt away by mistake: that is for another Vorsitzender or an admin
        if (! $by->is_admin && $by->id === $userId) {
            throw ValidationException::withMessages(['user_id' => 'Das eigene Amt ändert ein anderer Vorsitzender oder ein Admin.']);
        }
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
