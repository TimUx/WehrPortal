<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\User;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Notifications\PlanNotification;
use Throwable;

/** Publishing and sending the plan to everyone on it */
class PlanMailer
{
    public function publish(Plan $plan): int
    {
        $plan->update(['status' => Plan::PUBLISHED, 'published_at' => $plan->published_at ?? now()]);

        return $this->send($plan, firstTime: true);
    }

    /**
     * Every participant and Stationsverantwortliche gets their own part and the whole plan; a mail
     * problem is logged, not thrown.
     */
    public function send(Plan $plan, bool $firstTime = false): int
    {
        $sent = 0;
        $recipients = $plan->participants()->with('user')->get()->pluck('user')
            ->concat($plan->stations()->with('lead')->get()->pluck('lead'))
            ->filter()->unique('id');

        foreach ($recipients as $user) {
            /** @var User $user */
            try {
                $user->notify(new PlanNotification($plan, $firstTime));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $plan->update(['mailed_at' => now()]);

        return $sent;
    }
}
