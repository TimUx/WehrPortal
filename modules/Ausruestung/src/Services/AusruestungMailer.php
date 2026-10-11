<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Throwable;

/** Who looks after the equipment, and sending to them without letting a mail error undo anything */
class AusruestungMailer
{
    /** Gerätewarte, or the admins if nobody holds the role */
    public function managers(): Collection
    {
        $wardens = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'ausruestung')->where('role', 'geraetewart'))
            ->get();

        return $wardens->isNotEmpty() ? $wardens : User::active()->where('is_admin', true)->get();
    }

    public function toManagers(Notification $notification, ?User $except = null): void
    {
        $this->managers()
            ->reject(fn (User $manager) => $except && $manager->is($except))
            ->each(fn (User $manager) => $this->send($manager, $notification));
    }

    public function send(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
