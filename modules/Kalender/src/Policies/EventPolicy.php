<?php

namespace Modules\Kalender\src\Policies;

use App\Models\User;
use Extensions\Locations\Scopes\LocationScope;
use Illuminate\Auth\Access\Response;
use Modules\Kalender\src\Models\Event;

class EventPolicy
{
    public function view(User $user, Event $event): bool|Response
    {
        return Event::visibleTo($user)->whereKey($event->id)->exists()
            ? true
            : Response::denyAsNotFound();
    }

    public function manage(User $user, ?Event $event = null): bool
    {
        $canManage = $event === null
            ? $user->canManageAnyKalenderLocation() || $user->kalenderGroupIds() !== []
            : ($event->locationIds() === []
                ? $user->canManageKalender()
                : $user->canManageKalenderLocations($event->locationIds()))
                || $this->leadsAudience($user, $event);

        return $canManage && ($event === null || ! $event->isExternal());
    }

    /** Jugend- and Kinderwarte: a Termin for their own Abteilung and nobody else's */
    private function leadsAudience(User $user, Event $event): bool
    {
        if ($user->kalenderGroupIds() === []) {
            return false;
        }

        $scope = LocationScope::class;

        return ! $event->functions()->withoutGlobalScope($scope)->exists()
            && $user->canManageKalenderGroups($event->groups()->withoutGlobalScope($scope)->pluck('groups.id')->all());
    }
}
