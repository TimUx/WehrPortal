<?php

namespace Modules\Schichtplan\src\Livewire;

use Livewire\Component;
use Modules\Schichtplan\src\Models\Plan;

/** Planer: all plans. Everyone: the published plans they are on, with their own shifts. */
class PlanList extends Component
{
    public bool $showPast = false;

    public function render()
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

        return view('schichtplan::livewire.plan-list', compact('mine', 'all', 'manager'));
    }
}
