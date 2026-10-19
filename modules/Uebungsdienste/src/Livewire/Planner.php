<?php

namespace Modules\Uebungsdienste\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/**
 * The Übungsdienstplan of a year: the next Dienst on top, the coming ones below (past ones on
 * request). Topic and responsible people: every Vorstand member, right in the list; date, time
 * and place: admins on their own page.
 */
class Planner extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'vergangene', except: false)]
    public bool $showPast = false;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->year ??= ServicePlan::today()->year;
        $this->flash = session('uebungsdienste.flash');
    }

    public function saveTopic(int $serviceId, ?string $topic, ServicePlan $plan): void
    {
        $this->attempt(fn () => $plan->setTopic(Service::findOrFail($serviceId), $topic));
    }

    public function addResponsible(int $serviceId, $userId, ServicePlan $plan): void
    {
        if (! $userId) {
            return;
        }

        $this->attempt(fn () => $plan->addResponsible(Service::findOrFail($serviceId), User::findOrFail((int) $userId), auth()->user()));
    }

    public function removeResponsible(int $serviceId, int $userId, ServicePlan $plan): void
    {
        $plan->removeResponsible(Service::findOrFail($serviceId), $userId);
    }

    public function render(ServicePlan $plan)
    {
        $today = ServicePlan::today();
        $all = Service::with('responsible')->whereYear('date', $this->year)->orderBy('date')->orderBy('starts_at')->get();
        $rsvpCounts = Event::whereIn('id', $all->pluck('event_id')->filter())->where('rsvp', true)->with('responses')->get()
            ->mapWithKeys(fn (Event $event) => [$event->id => [
                'yes' => $event->responses->where('status', 'yes')->count(),
                'maybe' => $event->responses->where('status', 'maybe')->count(),
                'no' => $event->responses->where('status', 'no')->count(),
            ]]);
        $past = $all->filter(fn (Service $s) => $s->date->lt($today));
        $years = Service::selectRaw('distinct extract(year from date)::int as y')->pluck('y')->push($today->year)->push($this->year)->unique()->sort()->values();

        return view('uebungsdienste::livewire.planner', [
            'services' => $this->showPast ? $all : $all->diff($past)->values(),
            'rsvpCounts' => $rsvpCounts,
            'pastCount' => $past->count(),
            'years' => $years,
            'next' => $plan->next(),
            'today' => $today,
            'people' => User::active()->orderBy('name')->pluck('name', 'id'),
            'admin' => auth()->user()->can('plan-uebungsdienste'),
        ]);
    }

    /** Runs a change and shows its validation message */
    private function attempt(callable $change): void
    {
        $this->resetErrorBag();

        try {
            $change();
        } catch (ValidationException $e) {
            $this->addError('plan', collect($e->errors())->flatten()->first());
        }
    }
}
