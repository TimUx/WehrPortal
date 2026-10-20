<?php

namespace Modules\Kalender\src\Policies;

use App\Models\User;
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
        return $user->canManageKalender() && ($event === null || ! $event->isExternal());
    }
}
