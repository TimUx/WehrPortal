<?php

namespace Modules\Kalender\src\Policies;

use App\Models\User;
use Modules\Kalender\src\Models\Event;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return Event::visibleTo($user)->whereKey($event->id)->exists();
    }

    public function manage(User $user, ?Event $event = null): bool
    {
        return $user->canManageKalender() && ($event === null || ! $event->isExternal());
    }
}
