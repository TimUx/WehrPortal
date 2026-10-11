<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schichtplan\src\Models\Plan;

/** The plan by mail: your own shifts first, then the whole plan as a table */
class PlanNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Plan $plan, private readonly bool $firstTime = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->plan->load(['stations.lead', 'slots']);
        $assignments = $plan->assignments()->with('user')->get();

        $mine = $assignments->where('user_id', $notifiable->id)
            ->sortBy(fn ($a) => $plan->slots->firstWhere('id', $a->slot_id)?->starts_at)
            ->map(fn ($a) => [
                'time' => $plan->slots->firstWhere('id', $a->slot_id)?->label(),
                'station' => $plan->stations->firstWhere('id', $a->station_id)?->name,
            ])->values();

        $grid = $plan->slots->map(fn ($slot) => [
            'time' => $slot->label(),
            'cells' => $plan->stations->map(fn ($station) => $assignments->where('slot_id', $slot->id)->where('station_id', $station->id)
                ->map(fn ($a) => $a->user->name)->implode(', ') ?: '–')->all(),
        ]);

        return (new MailMessage)
            ->subject(($this->firstTime ? 'Schichtplan: ' : 'Schichtplan (aktualisiert): ').$plan->title)
            ->markdown('schichtplan::mail.plan', [
                'name' => $notifiable->name,
                'plan' => $plan,
                'mine' => $mine,
                'leading' => $plan->stations->where('lead_user_id', $notifiable->id)->pluck('name')->values(),
                'stations' => $plan->stations->pluck('name')->all(),
                'leads' => $plan->stations->map(fn ($station) => $station->lead?->name ?? '–')->all(),
                'grid' => $grid,
                'firstTime' => $this->firstTime,
                'url' => route('schichtplan.show', $plan),
                'salutation' => app(OrganizationSettings::class)->salutation(),
            ]);
    }
}
