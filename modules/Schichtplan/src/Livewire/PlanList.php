<?php

namespace Modules\Schichtplan\src\Livewire;

use Livewire\Component;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\SignupService;

/** Planer: all plans. Everyone: the published plans they are on, with their own shifts. */
class PlanList extends Component
{
    public bool $showPast = false;

    public function render(SignupService $signups)
    {
        $user = auth()->user();
        $manager = $user->canManageSchichtplan();

        $mine = Plan::where('status', Plan::PUBLISHED)
            ->where(fn ($q) => $q->whereHas('participants', fn ($p) => $p->where('user_id', $user->id))
                ->orWhereHas('stations', fn ($st) => $st->where('lead_user_id', $user->id)))
            ->where('ends_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Plan $plan) => [
                'plan' => $plan,
                'shifts' => $plan->assignments()->where('user_id', $user->id)->with(['slot', 'station'])->get()
                    ->sortBy(fn ($a) => $a->slot->starts_at)->values(),
                'leads' => $plan->stations()->where('lead_user_id', $user->id)->pluck('name'),
            ]);

        $all = $manager
            ? Plan::withCount(['participants', 'stations'])
                ->when(! $this->showPast, fn ($q) => $q->where('ends_at', '>=', now()->subDay()))
                ->orderBy('starts_at', $this->showPast ? 'desc' : 'asc')
                ->get()
            : collect();

        // Self sign-up plans still open, with their free places
        $open = $signups->openPlansFor($user)->map(fn (Plan $plan) => [
            'plan' => $plan,
            'free' => (int) $plan->stations()->sum('capacity') * $plan->slots()->where('starts_at', '>', now())->count()
                - $plan->assignments()->whereHas('slot', fn ($q) => $q->where('starts_at', '>', now()))->count(),
        ]);
        $requests = Assignment::where('status', Assignment::REQUESTED)->with(['plan', 'slot', 'station', 'user'])->get()
            ->filter(fn (Assignment $a) => SignupService::canApprove($user, $a))->groupBy('plan_id');

        return view('schichtplan::livewire.plan-list', compact('mine', 'all', 'manager', 'open', 'requests'));
    }
}
