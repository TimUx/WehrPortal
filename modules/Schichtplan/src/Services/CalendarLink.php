<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Modules\Schichtplan\src\Models\Plan;

/**
 * Schichtplan and Kalender, while the Kalender is on: publishing a plan puts it into the calendar
 * (self sign-up for its target group, otherwise for the people on it), and later changes follow.
 * A plan created from an event only links to it; that event stays the Terminverwalter's.
 */
class CalendarLink
{
    public static function available(): bool
    {
        return app(ModuleRegistry::class)->isActive('kalender');
    }

    public function sync(Plan $plan): void
    {
        if (! $plan->isPublished() || ! self::available()) {
            return;
        }
        // Linked to an event of the Kalender, or the created event was deleted there on purpose
        if (! $plan->event_owned && $plan->event_id || $plan->event_owned && ! $plan->event_id) {
            return;
        }

        $events = app(EventService::class);
        $data = ['title' => $plan->title, 'location' => $plan->location, 'starts_at' => $plan->starts_at, 'ends_at' => $plan->ends_at];
        [$groups, $functions, $users] = $plan->isSelfSignup()
            ? [array_map('intval', $plan->audience_groups ?? []), array_map('intval', $plan->audience_functions ?? []), []]
            : [[], [], $this->people($plan)];

        if ($event = $plan->event_id ? Event::find($plan->event_id) : null) {
            $events->update($event, $data, $groups, false, $users, $functions);

            return;
        }

        $event = $events->create([...$data, 'type' => 'veranstaltung', 'managed_by' => 'schichtplan'], $groups, User::find($plan->created_by), userIds: $users, functionIds: $functions)->first();
        $plan->update(['event_id' => $event->id, 'event_owned' => true]);
    }

    /** Deleting a plan takes the event it created along */
    public function detach(Plan $plan): void
    {
        if ($plan->event_owned && $plan->event_id) {
            Event::whereKey($plan->event_id)->delete();
        }
    }

    /** The plans of an event the Kamerad may open */
    public function plansFor(Event $event, User $user): Collection
    {
        return Plan::where('event_id', $event->id)->orderBy('starts_at')->get()
            ->filter(fn (Plan $plan) => Gate::forUser($user)->allows('view', $plan))->values();
    }

    /** Planer mode: participants and Stationsverantwortliche; never empty, which would mean everyone */
    private function people(Plan $plan): array
    {
        $ids = $plan->participants()->pluck('user_id')
            ->concat($plan->stations()->whereNotNull('lead_user_id')->pluck('lead_user_id'))
            ->push($plan->created_by)->filter()->unique()->values()->all();

        return array_map('intval', $ids);
    }
}
