<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Notifications\PlanNotification;
use Throwable;

/**
 * Publishing tells everyone under the bell; "Per E-Mail senden" is a separate step that mails the
 * plan (a self sign-up plan: the invitation to its target group, the shifts to who has some).
 */
class PlanMailer
{
    /** The plan becomes visible; participants and Stationsverantwortliche (self sign-up: the target group) get a bell entry */
    public function publish(Plan $plan): int
    {
        $plan->update(['status' => Plan::PUBLISHED, 'published_at' => $plan->published_at ?? now()]);
        app(CalendarLink::class)->sync($plan);
        app(ChangeNotifier::class)->remember($plan);

        return $plan->isSelfSignup()
            ? app(SignupService::class)->invite($plan, ['database'])
            : $this->tell($this->recipients($plan), fn () => (new PlanNotification($plan, firstTime: true))->onlyVia(['database']));
    }

    /**
     * The current plan by mail: every participant and Stationsverantwortliche gets their own part and
     * the whole plan; with self sign-up the rest of the target group gets the invitation. A mail
     * problem is logged, not thrown.
     */
    public function send(Plan $plan): int
    {
        $people = $this->recipients($plan);
        // The first mail is "Schichtplan: …", later ones "(aktualisiert)"
        $first = $plan->mailed_at === null;
        $sent = $this->tell($people, fn () => (new PlanNotification($plan, $first))->onlyVia(['mail']));
        if ($plan->isSelfSignup() && $plan->signupOpen()) {
            $sent += app(SignupService::class)->invite($plan, ['mail'], except: $people->pluck('id')->all());
        }

        $plan->update(['mailed_at' => now()]);
        app(ChangeNotifier::class)->remember($plan);

        return $sent;
    }

    private function recipients(Plan $plan): Collection
    {
        return $plan->participants()->with('user')->get()->pluck('user')
            ->concat($plan->stations()->with('lead')->get()->pluck('lead'))
            ->filter()->unique('id')->values();
    }

    private function tell(Collection $users, callable $notification): int
    {
        $sent = 0;
        foreach ($users as $user) {
            /** @var User $user */
            try {
                $user->notify($notification());
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
